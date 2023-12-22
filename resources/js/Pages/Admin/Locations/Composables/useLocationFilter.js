import {isAfter, isBefore, parse, parseISO} from 'date-fns'
import formatISO from 'date-fns/formatISO'
import {cloneDeep} from 'lodash'
import {computed, onMounted, ref, shallowRef, watch} from 'vue'

export default function useLocationFilter(canAdmin = false) {
    /**
     * @param {Date} date
     */
    const date = ref(parse('12:00:00', 'HH:mm:ss', new Date()))

    const maxReservationDate = ref(new Date())
    const serverLocations = shallowRef([])
    const serverDates = shallowRef({})
    const freeShifts = shallowRef([])
    const isLoading = ref(false)

    const getShifts = async () => {
        isLoading.value = true
        try {
            const path = canAdmin ? `/admin/assigned-shifts/${selectedDate.value}` : `/shifts/${selectedDate.value}`

            const response = await axios.get(path)
            serverLocations.value = response.data.locations
            serverDates.value = response.data.shifts
            freeShifts.value = response.data.freeShifts
            maxReservationDate.value = parseISO(response.data.maxDateReservation)
        } finally {
            isLoading.value = false
        }
    }

    onMounted(() => {
        getShifts()
    })

    const selectedDate = computed({
        get: () => date.value ? formatISO(date.value, {representation: 'date'}) : '',
        set: (value) => date.value = value,
    })

    watch(selectedDate, () => getShifts())

    const emptyShiftsForTime = ref([])

    const setReservations = (maxVolunteers, shift, location) => {

        const volunteers = shift.filterVolunteers?.sort((a, b) => {
            if (a.gender !== b.gender) {
                // If genders are different, sort in descending order of gender (ie, male first)
                return b.gender - a.gender;
            } else {
                // If genders are the same, sort in ascending order of shift_id
                return a.shift_id - b.shift_id;
            }
        });

        const length = maxVolunteers >= volunteers.length ? maxVolunteers - volunteers.length : maxVolunteers

        if (length) {
            const nullArray = Array(length).fill(null)
            shift.filterVolunteers = [...volunteers, ...nullArray]
            emptyShiftsForTime.value.push({
                startTime: parse(shift.start_time, 'HH:mm:ss', date.value),
                endTime: parse(shift.end_time, 'HH:mm:ss', date.value),
                location: location.name,
                locationId: location.id,
                currentVolunteers: volunteers,
                days: shift.js_days,
                available_from: shift.available_from,
                available_to: shift.available_to,
            })
        }
    }

    const addShift = (shifts, shift) => {
        if (shift.available_from) {
            const from = parseISO(shift.available_from)
            if (isBefore(date.value, from)) {
                return false
            }
        }
        if (shift.available_to) {
            // const to = parseISO(shift.available_to)
            const to = parseISO(`${shift.available_to}T23:59:59`)
            if (isAfter(date.value, to)) {
                return false
            }
        }
        shifts.push(shift)
        return true
    }

    const addLocation = (mappedLocations, location, shift) => {
        const alreadyAddedLocation = mappedLocations.find(l => l.id === location.id)
        if (!alreadyAddedLocation) {
            location.filterShifts = []
            if (addShift(location.filterShifts, shift)) {
                mappedLocations.push(location)
            }
        } else {
            if (!alreadyAddedLocation.filterShifts.find(s => s.id === shift.id)) {
                addShift(alreadyAddedLocation.filterShifts, shift)
            }
        }

    }

    const locations = computed(() => {
        if (!serverLocations?.value) {
            return []
        }
        const mappedLocations = []
        const myLocations = cloneDeep(serverLocations.value)
        emptyShiftsForTime.value = []
        for (const location of myLocations) {
            for (const shift of location.shifts) {
                const volunteers = shift.volunteers
                shift.filterVolunteers = volunteers.filter(volunteer => volunteer.shift_date === selectedDate.value)
                delete shift.volunteers
                if (location.requires_brother) {
                    let femaleCount = 0
                    for (const filVolunteer of shift.filterVolunteers) {
                        if (filVolunteer.gender === 'female') {
                            femaleCount++
                        }
                    }
                    shift.maxedFemales = femaleCount >= location.max_volunteers - 1
                }
                setReservations(location.max_volunteers, shift, location)
                const dayOfWeek = date.value.getDay()
                const mappedDay = shift.js_days[dayOfWeek]
                if (mappedDay === true) {
                    addLocation(mappedLocations, location, shift)
                }
            }
            delete location.shifts
        }
        return mappedLocations
    })

    return {
        date,
        emptyShiftsForTime,
        freeShifts,
        isLoading,
        locations,
        maxReservationDate,
        serverDates,
        getShifts,
    }
}
