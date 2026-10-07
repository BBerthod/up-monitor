import { ref, computed, onMounted, onUnmounted } from 'vue'

export interface TooltipData {
    x: number
    y: number
    date: string
    value: number
    status?: 'up' | 'down'
    min_ms?: number
    max_ms?: number
    uptime_percent?: number
    status_code?: number
    isAggregated?: boolean
}

export function useChartTooltip() {
    const tooltip = ref({
        show: false,
        x: 0,
        y: 0,
        svgX: 0,
        svgY: 0,
        data: null as TooltipData | null,
    })

    const isTouchDevice = ref(false)

    onMounted(() => {
        isTouchDevice.value = 'ontouchstart' in window || navigator.maxTouchPoints > 0
    })

    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']

    function formatDate(dateStr: string, period?: string): string {
        const date = new Date(dateStr)
        const month = months[date.getMonth()]
        const day = date.getDate()
        const year = date.getFullYear()
        const hours = String(date.getHours()).padStart(2, '0')
        const minutes = String(date.getMinutes()).padStart(2, '0')

        switch (period) {
            case '6mo':
            case '3mo':
                return `${month} '${String(year).slice(-2)}`
            case '1mo':
                return `${month} ${day}`
            case '7d':
                return `${days[date.getDay()]} ${day}`
            case '24h':
            case '1h':
                return `${month} ${day}, ${hours}:${minutes}`
            default:
                return `${month} ${day}, ${year} ${hours}:${minutes}`
        }
    }

    function showTooltip(event: MouseEvent | TouchEvent, data: TooltipData) {
        const clientX = 'touches' in event ? event.touches[0].clientX : event.clientX
        const clientY = 'touches' in event ? event.touches[0].clientY : event.clientY

        tooltip.value = {
            show: true,
            x: clientX + 16,
            y: clientY - 20,
            svgX: data.x,
            svgY: data.y,
            data,
        }
    }

    function updateTooltipPosition(event: MouseEvent | TouchEvent) {
        const clientX = 'touches' in event ? event.touches[0].clientX : event.clientX
        const clientY = 'touches' in event ? event.touches[0].clientY : event.clientY

        const vw = window.innerWidth
        const vh = window.innerHeight

        let x = clientX + 16
        let y = clientY - 20

        if (x + 200 > vw) x = clientX - 200
        if (y < 10) y = clientY + 20
        if (y + 150 > vh) y = vh - 160

        tooltip.value.x = x
        tooltip.value.y = y
    }

    function hideTooltip() {
        tooltip.value.show = false
        tooltip.value.data = null
    }

    function handleTouchStart(event: TouchEvent, data: TooltipData) {
        event.preventDefault()
        showTooltip(event, data)
    }

    function handleTouchMove(event: TouchEvent) {
        event.preventDefault()
        updateTooltipPosition(event)
    }

    function handleTouchEnd() {
        setTimeout(hideTooltip, 3000)
    }

    return {
        tooltip,
        isTouchDevice,
        showTooltip,
        updateTooltipPosition,
        hideTooltip,
        handleTouchStart,
        handleTouchMove,
        handleTouchEnd,
        formatDate,
        months,
        days,
    }
}
