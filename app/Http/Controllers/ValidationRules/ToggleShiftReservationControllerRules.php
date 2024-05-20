<?php

namespace App\Http\Controllers\ValidationRules;

use App\Actions\GetMaxShiftReservationDateAllowed;
use App\Actions\ValidateVolunteerIsAllowedToBeRosteredAction;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class ToggleShiftReservationControllerRules
{
    public function __construct(
        private readonly GetMaxShiftReservationDateAllowed            $getMaxShiftReservationDateAllowed,
        private readonly ValidateVolunteerIsAllowedToBeRosteredAction $validateVolunteerIsAllowedToBeRosteredAction,
    )
    {
    }

    //TODO Can this be migrated to a FormRequest class?
    public function execute(User $user, array $data, bool $isAdmin = false): array
    {
        $shiftDate = Carbon::parse($data['date']);
        $dayOfWeek = Str::of('day_')->append($shiftDate->dayName)->lower();
        return [
            'location'   => [
                'bail',
                'required',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('is_enabled', true),
            ],
            'shift'      => [
                'bail',
                'required',
                'integer',
                Rule::exists('shifts', 'id')
                    ->where('is_enabled', true)
                    ->where($dayOfWeek, true),
                Rule::when($data['do_reserve'] === true,
                    [
                        Rule::unique('shift_user', 'shift_id')
                            ->where('user_id', (int)$user->id)
                            ->where('shift_date', $shiftDate->toDateString())
                    ],
                    [
                        Rule::exists('shift_user', 'shift_id')
                            ->where('user_id', (int)$user->id)
                            ->where('shift_date', $shiftDate->toDateString())
                    ],
                ),
                function ($attribute, $value, $fail) use ($user, $data, $shiftDate, $isAdmin) {
                    // only validate if adding a shift
                    if (!$data['do_reserve']) {
                        return;
                    }
                    $this->isOverlappingShift($user, $shiftDate, $data['shift'], $isAdmin, $fail);
                    $this->isUserAllowedToReserveShifts($user, $shiftDate, $data['shift'], $fail);
                    $this->isUserActive($user, $data, $fail);
                },
            ],
            'do_reserve' => ['required', 'boolean'],
            'date'       => [
                'required',
                'date',
                'after_or_equal:today',
                //'before_or_equal:last day of next month zulu',
                function ($attribute, $value, $fail) use ($data, $isAdmin) {
                    // only validate if adding a shift
                    if ($isAdmin || !$data['do_reserve']) {
                        return;
                    }
                    $date = Carbon::createFromFormat('Y-m-d', $value);
                    $this->isShiftInAllowedPeriod($date, $fail);
                },
            ],
        ];
    }

    private function isOverlappingShift(User $user, Carbon $shiftDate, int $shiftId, bool $isAdmin, $fail): void
    {
        $userShiftsOnDate = Shift::select('shifts.*')
            ->with([
                'location' => fn(BelongsTo $query) => $query
                    ->select(['id', 'name'])
                    ->where('is_enabled', true)
            ])
            ->join(table: 'locations', first: fn(JoinClause $query) => $query
                ->on('locations.id', '=', 'shifts.location_id')
                ->where('locations.is_enabled', true)
            )
            ->rightJoin(table: 'shift_user', first: fn(JoinClause $query) => $query
                ->on('shift_user.shift_id', '=', 'shifts.id')
                ->where('shift_user.user_id', $user->id)
                ->where('shift_user.shift_date', $shiftDate->toDateString())
            )
            ->where('shifts.is_enabled', true)
            ->where(fn(Builder $query) => $query
                ->whereNull('shifts.available_from')
                ->orWhere('shifts.available_from', '<=', $shiftDate->toDateString())
            )
            ->where(fn(Builder $query) => $query
                ->whereNull('shifts.available_to')
                ->orWhere('shifts.available_to', '>=', $shiftDate->toDateString())
            )
            ->get();


        $requestedShift       = Shift::find($shiftId);
        $requestedShiftPeriod = CarbonPeriod::create(
            $requestedShift->start_time,
            $requestedShift->end_time,
        );

        /** @var Shift $overlappingShift */
        $overlappingShift = $userShiftsOnDate->first(
            fn(Shift $currentShift) => $requestedShiftPeriod->overlaps(
                CarbonPeriod::create($currentShift->start_time, $currentShift->end_time),
            ),
        );

        if ($overlappingShift) {
            $start   = Carbon::parse($overlappingShift->start_time)->format('h:i a');
            $end     = Carbon::parse($overlappingShift->end_time)->format('h:i a');
            $message = $isAdmin
                ? "Sorry, $user->name is already on a shift that overlaps this shift at {$overlappingShift->location->name} between $start and $end."
                : "Sorry, you're already assigned to shift at this time\n{$overlappingShift->location->name} - {$overlappingShift->start_time} and {$overlappingShift->end_time}.";

            $fail($message);
        }
    }

    /**
     * @throws \RuntimeException
     */
    private function isUserAllowedToReserveShifts(User $user, Carbon $shiftDate, int $shiftId, $fail): void
    {
        $location = Location::whereRelation('shifts', 'id', $shiftId)->first();
        if (!$location) {
            throw new RuntimeException('Location not found for shift');
        }
        if (!$location->requires_brother) {
            return;
        }

        $shiftUsers = ShiftUser::with(['user' => fn(BelongsTo $query) => $query
            ->select(['id', 'gender'])])
            ->where('shift_id', $shiftId)
            ->where('shift_date', $shiftDate->toDateString())
            ->get();

        if ($shiftUsers->count() < $location->max_volunteers - 1) {
            // not enough volunteers to require a brother
            return;
        }

        $currentVolunteers = $shiftUsers->map(fn(ShiftUser $shiftUser) => $shiftUser->user);
        $isAllowed         = $this->validateVolunteerIsAllowedToBeRosteredAction->execute($location, $user, $currentVolunteers);
        if (is_string($isAllowed)) {
            $fail($isAllowed);
        }
    }

    private function isShiftInAllowedPeriod(Carbon $shiftDate, $fail): void
    {
        $maxShiftReservationDateAllowed = $this->getMaxShiftReservationDateAllowed->execute();

        if ($shiftDate->isAfter($maxShiftReservationDateAllowed)) {
            $fail($this->getMaxShiftReservationDateAllowed->getFailMessage());
        }
    }

    private function isUserActive(User $user, array $data, $fail): void
    {
        if (!$data['do_reserve'] || !isset($data['user'])) {
            // test only if there is a 'user' key (admin-only feature) and adding to a shift
            return;
        }
        if (!$user->is_enabled) {
            $fail("Sorry, $user->name has been disabled. You cannot reserve a shift for them.");
        }
    }
}
