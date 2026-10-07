<script setup lang="ts">
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import { useAuth } from '@/Composables/useAuth'
import NavItem from './NavItem.vue'
import NavSection from './NavSection.vue'
import NavBadge from './NavBadge.vue'

const props = defineProps<{
    collapsed?: boolean
    onItemClick?: () => void  // called after any nav item click (mobile: closes drawer)
}>()

const page = usePage()
const { isAdmin } = useAuth()

// Triage data from shared Inertia props — defensive: may be null (guest)
const triage = computed(() => (page.props as any).triage ?? null)
const counts = computed(() => triage.value?.counts ?? null)

// Per-domain badge counts
const totalCount = computed(() => counts.value?.total ?? 0)
const criticalCount = computed(() => counts.value?.critical ?? 0)
const availabilityCount = computed(() => counts.value?.domains?.availability ?? 0)
const seoCount = computed(() => counts.value?.domains?.seo_business ?? 0)
const infraCount = computed(() => counts.value?.domains?.infrastructure ?? 0)
const alertingCount = computed(() => counts.value?.domains?.alerting ?? 0)

const isActive = computed(() => (href: string) => page.url.startsWith(href))
</script>

<template>
    <nav aria-label="Main" class="flex flex-col flex-1 min-h-0">
    <!-- ─── Overview ─── -->
    <div class="space-y-0.5 px-3 pb-2" :class="collapsed ? 'pt-2' : 'pt-4'">
        <NavItem
            label="Overview"
            href="/dashboard"
            icon="grid"
            :collapsed="collapsed"
            :on-click="onItemClick"
        />
        <NavItem
            label="Inbox"
            href="/inbox"
            icon="bell"
            :badge="totalCount"
            :badge-critical="criticalCount > 0"
            :collapsed="collapsed"
            :on-click="onItemClick"
        />
    </div>

    <!-- ─── Availability ─── -->
    <div class="px-3">
        <NavSection
            title="Availability"
            domain="availability"
            :badge="availabilityCount"
            :collapsed="collapsed"
            :advanced-hrefs="['/events']"
        >
            <NavItem label="Monitors"     href="/monitors"      icon="activity"       :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Incidents"    href="/incidents"     icon="alert-triangle" :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Status Pages" href="/status-pages"  icon="globe"          :collapsed="collapsed" :on-click="onItemClick" />

            <template #advanced>
                <NavItem label="Events" href="/events" icon="bell" :collapsed="collapsed" :on-click="onItemClick" />
            </template>
        </NavSection>
    </div>

    <!-- ─── SEO & Business ─── -->
    <div class="px-3">
        <NavSection
            title="SEO & Business"
            domain="seo_business"
            :badge="seoCount"
            :collapsed="collapsed"
        >
            <NavItem label="Sites"             href="/sites"              icon="sites"            :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Action Plan"       href="/action-plan"        icon="list-check"       :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="KPI & Trends"      href="/kpi-trends"         icon="chart-bar"        :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Striking Distance" href="/striking-distance"  icon="target"           :collapsed="collapsed" :on-click="onItemClick" />
        </NavSection>
    </div>

    <!-- ─── Infrastructure ─── -->
    <div class="px-3">
        <NavSection
            title="Infrastructure"
            domain="infrastructure"
            :badge="infraCount"
            :collapsed="collapsed"
            :advanced-hrefs="['/deployments']"
        >
            <NavItem label="Servers"       href="/servers"  icon="server" :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Cache Warming" href="/warming"  icon="flame"  :collapsed="collapsed" :on-click="onItemClick" />

            <template #advanced>
                <NavItem label="Deployments" href="/deployments" icon="deployments" :collapsed="collapsed" :on-click="onItemClick" />
            </template>
        </NavSection>
    </div>

    <!-- ─── Alerting ─── -->
    <div class="px-3">
        <NavSection
            title="Alerting"
            domain="alerting"
            :badge="alertingCount"
            :collapsed="collapsed"
        >
            <NavItem label="Channels"             href="/channels"           icon="bell-alert" :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Notification History" href="/notification-logs"  icon="bell-ring"  :collapsed="collapsed" :on-click="onItemClick" />
            <NavItem label="Sources"              href="/sources"            icon="server"     :collapsed="collapsed" :on-click="onItemClick" />
        </NavSection>
    </div>

    <!-- ─── Footer (Settings + optional Users + Digest) ─── -->
    <div class="px-3 mt-auto pt-3 pb-2 border-t border-zinc-800 space-y-0.5">
        <NavItem
            label="Settings"
            href="/settings"
            icon="cog"
            :collapsed="collapsed"
            :on-click="onItemClick"
        />
        <NavItem
            v-if="isAdmin"
            label="Users"
            href="/admin/users"
            icon="users"
            :collapsed="collapsed"
            :on-click="onItemClick"
        />
        <NavItem
            label="Digest"
            href="/digest"
            icon="digest"
            :collapsed="collapsed"
            :on-click="onItemClick"
        />
    </div>
    </nav>
</template>
