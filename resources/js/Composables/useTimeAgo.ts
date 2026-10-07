/**
 * Shared time-ago formatter.
 *
 * Behaviour:
 *  < 1 min     → "Just now"
 *  < 90 min    → "Nm ago"
 *  < 48 h      → "Nh ago"
 *  ≥ 48 h      → "N days ago"
 *
 * Used by Public.vue (status pages) and any component that needs
 * human-readable relative timestamps.
 */
export function useTimeAgo() {
    function timeAgo(iso: string | null | undefined): string {
        if (!iso) return ''
        const ms = Date.now() - new Date(iso).getTime()
        const m = Math.floor(ms / 60_000)
        if (m < 1) return 'Just now'
        if (m < 90) return `${m}m ago`
        const h = Math.floor(m / 60)
        if (h < 48) return `${h}h ago`
        const d = Math.floor(h / 24)
        return d === 1 ? 'Yesterday' : `${d} days ago`
    }

    return { timeAgo }
}
