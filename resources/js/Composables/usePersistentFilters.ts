import { ref, watch, onMounted } from 'vue'
import { router } from '@inertiajs/vue3'

interface StoredFilters<T> {
    filters: T
    timestamp: number
}

const DEFAULT_TTL = 24 * 60 * 60 * 1000

export function usePersistentFilters<T extends Record<string, unknown>>(
    key: string,
    initialFilters: T,
    routeName: string,
    ttl: number = DEFAULT_TTL,
    /**
     * Props to partial-reload when filters change. Without it every filter
     * tweak re-fetched the page's entire prop payload.
     */
    only: string[] = []
) {
    const filters = ref<T>({ ...initialFilters }) as { value: T }
    const isInitialized = ref(false)

    const storageKey = `filters_${key}`

    const loadFilters = (): T | null => {
        try {
            const stored = localStorage.getItem(storageKey)
            if (!stored) return null

            const data: StoredFilters<T> = JSON.parse(stored)
            const now = Date.now()

            if (now - data.timestamp > ttl) {
                localStorage.removeItem(storageKey)
                return null
            }

            return data.filters
        } catch {
            return null
        }
    }

    const saveFilters = (newFilters: T) => {
        try {
            const data: StoredFilters<T> = {
                filters: newFilters,
                timestamp: Date.now(),
            }
            localStorage.setItem(storageKey, JSON.stringify(data))
        } catch {
            // Silently fail if localStorage is not available
        }
    }

    const clearFilters = () => {
        try {
            localStorage.removeItem(storageKey)
        } catch {
            // Silently fail
        }
    }

    const applyFilters = () => {
        if (!isInitialized.value) return

        const params: Record<string, string> = {}
        Object.entries(filters.value).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') {
                params[k] = String(v)
            }
        })
        saveFilters(filters.value)
        router.get(route(routeName), params, {
            preserveState: true,
            preserveScroll: true,
            ...(only.length > 0 ? { only } : {}),
        })
    }

    const hasStoredFilters = (): boolean => {
        return loadFilters() !== null
    }

    onMounted(() => {
        const stored = loadFilters()
        if (stored) {
            const currentParams = new URLSearchParams(window.location.search)
            const hasActiveFilters = Array.from(currentParams.keys()).length > 0
            // Only navigate when the stored filters actually differ from the
            // page's current state. Defaults are saved on first visit, so on
            // every later visit `stored` exists — comparing prevents the
            // redundant second GET that used to fire on each page load.
            const differs = JSON.stringify({ ...filters.value, ...stored }) !== JSON.stringify(filters.value)

            if (!hasActiveFilters && differs) {
                filters.value = { ...filters.value, ...stored } as T
                isInitialized.value = true
                applyFilters()
                return
            }
        }

        isInitialized.value = true
        saveFilters(filters.value)
    })

    watch(
        filters,
        () => {
            if (isInitialized.value) {
                applyFilters()
            }
        },
        { deep: true }
    )

    return {
        filters,
        clearFilters,
        hasStoredFilters,
        applyFilters,
    }
}

export function useSimplePersistentFilter(
    key: string,
    initialValue: string,
    routeName: string,
    ttl: number = DEFAULT_TTL
) {
    const storageKey = `filter_${key}`
    const value = ref(initialValue)
    const isInitialized = ref(false)

    const loadValue = (): string | null => {
        try {
            const stored = localStorage.getItem(storageKey)
            if (!stored) return null

            const data = JSON.parse(stored)
            const now = Date.now()

            if (now - data.timestamp > ttl) {
                localStorage.removeItem(storageKey)
                return null
            }

            return data.value
        } catch {
            return null
        }
    }

    const saveValue = (val: string) => {
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify({
                    value: val,
                    timestamp: Date.now(),
                })
            )
        } catch {
            // Silently fail
        }
    }

    const applyFilter = (val: string | null) => {
        router.get(route(routeName), val ? { status: val } : {}, { preserveState: true })
    }

    onMounted(() => {
        const stored = loadValue()
        if (stored) {
            const currentParams = new URLSearchParams(window.location.search)
            const hasActiveFilters = currentParams.has('status')

            if (!hasActiveFilters && stored !== value.value) {
                value.value = stored
                isInitialized.value = true
                applyFilter(stored)
                return
            }
        }

        isInitialized.value = true
        saveValue(value.value)
    })

    watch(value, (newVal) => {
        if (isInitialized.value) {
            saveValue(newVal)
            applyFilter(newVal)
        }
    })

    return {
        value,
    }
}
