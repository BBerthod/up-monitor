import { onMounted, onUnmounted } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

/**
 * Subscribe an Inertia page to the team's broadcast channel and partial-reload
 * the listed props when relevant events land.
 *
 * Events are debounced (500 ms) per event type, and can be FILTERED:
 * `monitorId` / `serverId` restrict monitor.checked and server.metric.received
 * to one entity. Without the filter, a detail page reloaded its entire payload
 * every time ANY monitor of the team was checked — on Monitors/Show that was
 * eight heavy props re-fetched once a minute per sibling monitor.
 *
 * The channel itself is shared and long-lived: unmount detaches only the
 * listeners this composable attached (stopListening) instead of Echo.leave(),
 * which tore down the team channel on every page navigation and forced a
 * resubscribe + /broadcasting/auth POST on the next page.
 */
export function useRealtimeUpdates(options: {
    onMonitorChecked?: string[]
    onLighthouseCompleted?: string[]
    onWarmRunProgress?: string[]
    onIncidentCreated?: string[]
    onIncidentResolved?: string[]
    onServerMetricReceived?: string[]
    onInsightChanged?: string[]
    onRefresh?: () => void
    /** Only react to monitor.checked events for this monitor. */
    monitorId?: number
    /** Only react to server.metric.received events for this server. */
    serverId?: number
}): void {
    const {
        onMonitorChecked = [],
        onLighthouseCompleted = [],
        onWarmRunProgress,
        onIncidentCreated = [],
        onIncidentResolved = [],
        onServerMetricReceived = [],
        onInsightChanged = [],
        onRefresh,
        monitorId,
        serverId,
    } = options

    const teamId = (usePage().props as any).auth?.team?.id
    const Echo = (window as any).Echo

    if (!Echo || !teamId) return

    const channelName = `team.${teamId}`

    const timers = new Map<string, ReturnType<typeof setTimeout>>()
    // [event name, handler] pairs actually attached, so unmount can detach
    // exactly these and nothing else.
    const attached: Array<[string, (event: any) => void]> = []

    const debounced = (key: string, props: string[]) => {
        const existing = timers.get(key)
        if (existing) clearTimeout(existing)
        timers.set(key, setTimeout(() => {
            onRefresh?.()
            router.reload({ only: props })
        }, 500))
    }

    const register = (
        channel: any,
        event: string,
        props: string[] | undefined,
        accepts?: (event: any) => boolean,
    ) => {
        if (!props || props.length === 0) return

        const handler = (payload: any) => {
            if (accepts && !accepts(payload)) return
            debounced(event, props)
        }

        channel.listen(event, handler)
        attached.push([event, handler])
    }

    onMounted(() => {
        const channel = Echo.private(channelName)

        register(channel, '.monitor.checked', onMonitorChecked,
            monitorId !== undefined ? (e) => e?.monitor?.id === monitorId : undefined)
        // LighthouseAuditCompleted broadcasts a flat monitor_id, unlike
        // MonitorChecked's nested monitor.id.
        register(channel, '.lighthouse.completed', onLighthouseCompleted,
            monitorId !== undefined ? (e) => e?.monitor_id === monitorId : undefined)
        register(channel, '.incident.created', onIncidentCreated)
        register(channel, '.incident.resolved', onIncidentResolved)
        register(channel, '.server.metric.received', onServerMetricReceived,
            serverId !== undefined ? (e) => e?.server?.id === serverId : undefined)
        register(channel, '.insight.changed', onInsightChanged)
        register(channel, '.warm.run.progress', onWarmRunProgress,
            (e) => Boolean(e?.completed))
    })

    onUnmounted(() => {
        timers.forEach((timer) => clearTimeout(timer))
        timers.clear()

        const channel = Echo.private(channelName)
        for (const [event, handler] of attached) {
            channel.stopListening(event, handler)
        }
    })
}
