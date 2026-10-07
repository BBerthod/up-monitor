<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import NavIcon from '@/Components/Nav/NavIcon.vue'

export interface TriageItemData {
    id: number
    severity: 'critical' | 'warning' | 'info'
    type: string
    domain: 'availability' | 'seo_business' | 'infrastructure' | 'alerting'
    title: string
    /** Type label from InsightType::label(), e.g. "Health drop". */
    label?: string
    /** Readable headline: the detector's title, or label + entity when that title is a raw slug. */
    display_title?: string
    site?: { id: number; name: string } | null
    server?: { id: number; name: string } | null
    monitor_id?: number | null
    detected_at: string
    impact_score?: number | null
    fix_url?: string | null
    acknowledge_url?: string | null
    vikunja_url?: string | null
    snooze_url?: string | null
}

const props = defineProps<{ item: TriageItemData }>()
const emit = defineEmits<{ acknowledged: [id: number] }>()

// ─── Vikunja ──────────────────────────────────────────────────────────────────
const sendingToVikunja = ref(false)

// ─── Snooze dropdown ──────────────────────────────────────────────────────────
const snoozeOpen = ref(false)

const snoozeDurations: Array<{ label: string; value: string; serverHealthOnly?: boolean }> = [
    { label: '1 hour',          value: '1h' },
    { label: '1 day',           value: '1d' },
    { label: '7 days',          value: '7d' },
    { label: 'Until recovery',  value: 'recovery', serverHealthOnly: true },
]

const visibleDurations = computed(() =>
    snoozeDurations.filter(d => !d.serverHealthOnly || props.item?.type === 'server_health')
)

function snooze(duration: string) {
    const url = props.item?.snooze_url
    if (!url) return
    snoozeOpen.value = false
    router.post(url, { duration }, { preserveScroll: true })
}

// Close on Escape or outside click
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') snoozeOpen.value = false
}

function onOutsideClick(e: MouseEvent) {
    const el = snoozeWrapRef.value
    if (el && !el.contains(e.target as Node)) snoozeOpen.value = false
}

const snoozeWrapRef = ref<HTMLElement | null>(null)

onMounted(() => {
    document.addEventListener('keydown', onKeydown)
    document.addEventListener('mousedown', onOutsideClick)
})

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown)
    document.removeEventListener('mousedown', onOutsideClick)
})

// ─── Severity / domain helpers ────────────────────────────────────────────────
const domainIcon = computed((): string => {
    const map: Record<string, string> = {
        availability: 'activity', seo_business: 'sites', infrastructure: 'server', alerting: 'bell',
    }
    return map[props.item?.domain] ?? 'grid'
})

const severityClasses = computed(() => {
    const s = props.item?.severity
    if (s === 'critical') return 'bg-[var(--color-danger)]/15 text-[var(--color-danger)] border-[var(--color-danger)]/25'
    if (s === 'warning') return 'bg-[var(--color-warning)]/15 text-[var(--color-warning)] border-[var(--color-warning)]/25'
    return 'bg-[var(--color-text-faint)]/15 text-[var(--color-text-muted)] border-[var(--color-border)]'
})
const severityDot = computed(() => {
    const s = props.item?.severity
    if (s === 'critical') return 'bg-[var(--color-danger)]'
    if (s === 'warning') return 'bg-[var(--color-warning)]'
    return 'bg-[var(--color-text-faint)]'
})
const entityLabel = computed(() => props.item?.site?.name ?? props.item?.server?.name ?? null)

const timeAgo = (iso: string | null | undefined): string => {
    if (!iso) return ''
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
    if (m < 1) return 'just now'
    if (m < 60) return `${m}m ago`
    if (m < 1440) return `${Math.floor(m / 60)}h ago`
    return `${Math.floor(m / 1440)}d ago`
}

function sendToVikunja() {
    const url = props.item?.vikunja_url
    if (!url) return
    sendingToVikunja.value = true
    router.post(url, {}, {
        preserveScroll: true,
        onFinish: () => { sendingToVikunja.value = false },
    })
}

function acknowledge() {
    const url = props.item?.acknowledge_url
    if (!url) return
    router.post(url, {}, { preserveScroll: true, onSuccess: () => emit('acknowledged', props.item.id) })
}
</script>

<template>
    <div class="group flex items-center gap-3 py-2 px-4 hover:bg-[var(--color-surface-1)] transition-colors">
        <span class="flex-shrink-0 w-1.5 h-1.5 rounded-full" :class="severityDot" />
        <span class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded text-[var(--color-text-muted)]">
            <NavIcon :name="domainIcon" />
        </span>
        <div class="flex-1 min-w-0">
            <div class="flex items-center justify-between gap-2 flex-wrap">
                <div class="min-w-0 flex items-center gap-2 flex-wrap">
                    <p class="text-sm font-medium text-[var(--color-text-primary)] leading-snug truncate">{{ item?.display_title ?? item?.title }}</p>
                    <span v-if="entityLabel" class="text-xs text-[var(--color-text-muted)] font-mono truncate">{{ entityLabel }}</span>
                    <span class="text-xs text-[var(--color-text-faint)]">{{ timeAgo(item?.detected_at) }}</span>
                    <span v-if="item?.impact_score != null" class="text-[10px] font-mono text-[var(--color-text-faint)]">impact {{ item.impact_score }}</span>
                </div>
                <span class="flex-shrink-0 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold uppercase tracking-wide border" :class="severityClasses">{{ item?.severity }}</span>
            </div>
            <div class="flex items-center gap-2 mt-1 opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity">
                <Link v-if="item?.fix_url" :href="item.fix_url" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-emerald-500/20 transition-colors">
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 4V2"/><path d="M15 16v-2"/><path d="M8 9h2"/><path d="M20 9h2"/><path d="M17.8 11.8 19 13"/><path d="M15 9h.01"/><path d="M17.8 6.2 19 5"/><path d="m3 21 9-9"/><path d="M12.2 6.2 11 5"/></svg>
                    Fix
                </Link>
                <button v-if="item?.acknowledge_url" type="button" class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-white/5 text-zinc-400 border border-white/10 hover:bg-white/10 hover:text-zinc-300 transition-colors" @click="acknowledge">Acknowledge</button>

                <!-- Manual promotion to the Vikunja board: the automatic path only
                     fires for CRITICAL problems that persist, so anything else that
                     is worth doing has to be sent by hand. -->
                <button
                    v-if="item?.vikunja_url"
                    type="button"
                    :disabled="sendingToVikunja"
                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-sky-500/10 text-sky-400 border border-sky-500/20 hover:bg-sky-500/20 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                    @click="sendToVikunja"
                >
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
                    {{ sendingToVikunja ? 'Envoi…' : 'Vikunja' }}
                </button>

                <!-- Snooze dropdown -->
                <div v-if="item?.snooze_url" ref="snoozeWrapRef" class="relative">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-medium bg-white/5 text-zinc-400 border border-white/10 hover:bg-white/10 hover:text-zinc-300 transition-colors"
                        @click.stop="snoozeOpen = !snoozeOpen"
                    >
                        <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        Snooze
                        <svg class="w-2.5 h-2.5 transition-transform" :class="snoozeOpen ? 'rotate-180' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </button>

                    <div
                        v-if="snoozeOpen"
                        class="absolute bottom-full left-0 mb-1.5 z-50 min-w-[140px] rounded-lg bg-[#1a1a1c] border border-white/10 shadow-xl overflow-hidden"
                    >
                        <button
                            v-for="d in visibleDurations"
                            :key="d.value"
                            type="button"
                            class="w-full text-left px-3 py-2 text-xs text-zinc-300 hover:bg-white/8 hover:text-white transition-colors"
                            @click="snooze(d.value)"
                        >
                            {{ d.label }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
