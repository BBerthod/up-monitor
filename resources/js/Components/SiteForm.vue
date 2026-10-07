<script setup lang="ts">
import { ref } from 'vue'
import type { InertiaForm } from '@inertiajs/vue3'

// ---------------------------------------------------------
// Types
// ---------------------------------------------------------

export interface SiteFormData {
    alias: string
    organization: string
    primary_domain: string
    domains: string[]
    locales: string[]
    primary_locale: string
    type: string
    health_endpoint: string
    gsc_property: string
    bing_url: string
    ga4_property: string
    sitemap_path: string
    sitemap_locale_pattern: string
    key_pages: string[]
    ad_networks: string[]
    merchant_domains: string[]
    amazon_tag: string
    dokploy_app_id: string
    dokploy_resource_type: string
    is_active: boolean
    report_frequency: string
    report_recipients: string[]
}

const props = defineProps<{
    form: InertiaForm<SiteFormData>
    submitLabel?: string
}>()

defineEmits<{
    submit: []
}>()

// ---------------------------------------------------------
// Array-field helpers
// ---------------------------------------------------------

const newDomain = ref('')
const newLocale = ref('')
const newKeyPage = ref('')
const newAdNetwork = ref('')
const newMerchantDomain = ref('')
const newReportRecipient = ref('')

const addItem = (list: string[], val: string, reset: () => void) => {
    const trimmed = val.trim()
    if (trimmed && !list.includes(trimmed)) {
        list.push(trimmed)
    }
    reset()
}

const removeItem = (list: string[], index: number) => {
    list.splice(index, 1)
}

const handleAddKeyDown = (
    event: KeyboardEvent,
    list: string[],
    val: string,
    reset: () => void,
) => {
    if (event.key === 'Enter') {
        event.preventDefault()
        addItem(list, val, reset)
    }
}

// ---------------------------------------------------------
// Site types
// ---------------------------------------------------------

const siteTypes = [
    { value: 'laravel', label: 'Laravel' },
    { value: 'wordpress', label: 'WordPress' },
    { value: 'static', label: 'Static' },
    { value: 'compose', label: 'Compose' },
]

const dokployResourceTypes = [
    { value: 'application', label: 'Application' },
    { value: 'compose', label: 'Compose' },
]

const reportFrequencies = [
    { value: 'none', label: 'Aucun' },
    { value: 'weekly', label: 'Hebdomadaire' },
    { value: 'monthly', label: 'Mensuel' },
]
</script>

<template>
    <form @submit.prevent="$emit('submit')" class="space-y-8" novalidate>

        <!-- ==================== BASICS ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">Basics</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- Alias -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        Alias <span class="text-red-400">*</span>
                    </label>
                    <input
                        v-model="form.alias"
                        type="text"
                        class="form-input w-full"
                        placeholder="my-site"
                        required
                        aria-describedby="alias-error"
                    />
                    <p v-if="form.errors.alias" id="alias-error" class="text-sm text-red-400 mt-1">{{ form.errors.alias }}</p>
                </div>

                <!-- Organization -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Organization</label>
                    <input
                        v-model="form.organization"
                        type="text"
                        class="form-input w-full"
                        placeholder="Radiank"
                    />
                    <p v-if="form.errors.organization" class="text-sm text-red-400 mt-1">{{ form.errors.organization }}</p>
                </div>
            </div>

            <!-- Type -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Type <span class="text-red-400">*</span>
                </label>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                    <div
                        v-for="t in siteTypes"
                        :key="t.value"
                        @click="form.type = t.value"
                        :class="[
                            'p-3 rounded-lg cursor-pointer transition-colors border text-center text-sm font-medium',
                            form.type === t.value
                                ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400'
                                : 'bg-white/5 border-white/10 text-zinc-400 hover:bg-white/10 hover:text-white'
                        ]"
                        role="radio"
                        :aria-checked="form.type === t.value"
                        tabindex="0"
                        @keydown.enter.prevent="form.type = t.value"
                        @keydown.space.prevent="form.type = t.value"
                    >
                        {{ t.label }}
                    </div>
                </div>
                <p v-if="form.errors.type" class="text-sm text-red-400 mt-1">{{ form.errors.type }}</p>
            </div>

            <!-- Primary domain -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Primary Domain <span class="text-red-400">*</span>
                </label>
                <input
                    v-model="form.primary_domain"
                    type="text"
                    class="form-input w-full font-mono"
                    placeholder="example.com"
                    required
                />
                <p v-if="form.errors.primary_domain" class="text-sm text-red-400 mt-1">{{ form.errors.primary_domain }}</p>
            </div>

            <!-- Health endpoint -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Health Endpoint <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <input
                    v-model="form.health_endpoint"
                    type="text"
                    class="form-input w-full font-mono"
                    placeholder="/up"
                />
                <p v-if="form.errors.health_endpoint" class="text-sm text-red-400 mt-1">{{ form.errors.health_endpoint }}</p>
            </div>

            <!-- Active toggle -->
            <div class="flex items-center justify-between py-3 px-4 rounded-lg bg-white/5 border border-white/10">
                <div>
                    <p class="text-sm font-medium text-white">Active</p>
                    <p class="text-xs text-zinc-500 mt-0.5">Enable monitoring and SEO tracking for this site.</p>
                </div>
                <button
                    type="button"
                    role="switch"
                    :aria-checked="form.is_active"
                    @click="form.is_active = !form.is_active"
                    :class="[
                        'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                        form.is_active ? 'bg-emerald-500' : 'bg-zinc-700'
                    ]"
                >
                    <span
                        :class="[
                            'pointer-events-none inline-block h-5 w-5 rounded-full bg-white shadow-md transform transition-transform duration-200',
                            form.is_active ? 'translate-x-5' : 'translate-x-0'
                        ]"
                    />
                </button>
            </div>
        </section>

        <!-- ==================== DOMAINS & LOCALES ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">Domains &amp; Locales</h2>

            <!-- Domains list -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Domains <span class="text-red-400">*</span>
                </label>
                <p class="text-xs text-zinc-500 mb-3">
                    Use <code class="text-zinc-400 bg-white/5 px-1 rounded">{'{locale}'}</code> as a placeholder for multi-locale subdomains, e.g. <code class="text-zinc-400 bg-white/5 px-1 rounded">{'{locale}'}.example.com</code>.
                </p>
                <div class="space-y-2 mb-3">
                    <div
                        v-for="(domain, i) in form.domains"
                        :key="i"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10 group"
                    >
                        <span class="flex-1 text-sm font-mono text-zinc-300 truncate">{{ domain }}</span>
                        <button
                            type="button"
                            @click="removeItem(form.domains, i)"
                            class="shrink-0 text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove domain ${domain}`"
                        >
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newDomain"
                        type="text"
                        class="form-input flex-1 font-mono"
                        placeholder="example.com or {locale}.example.com"
                        @keydown="handleAddKeyDown($event, form.domains, newDomain, () => (newDomain = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.domains, newDomain, () => (newDomain = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.domains" class="text-sm text-red-400 mt-1">{{ form.errors.domains }}</p>
            </div>

            <!-- Locales list -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Locales <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <div class="flex flex-wrap gap-2 mb-3">
                    <span
                        v-for="(locale, i) in form.locales"
                        :key="i"
                        class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-white/5 border border-white/10 text-xs text-zinc-300 font-mono"
                    >
                        {{ locale }}
                        <button
                            type="button"
                            @click="removeItem(form.locales, i)"
                            class="text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove locale ${locale}`"
                        >
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </span>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newLocale"
                        type="text"
                        class="form-input flex-1"
                        placeholder="fr, en, de…"
                        @keydown="handleAddKeyDown($event, form.locales, newLocale, () => (newLocale = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.locales, newLocale, () => (newLocale = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.locales" class="text-sm text-red-400 mt-1">{{ form.errors.locales }}</p>
            </div>

            <!-- Primary locale -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Primary Locale <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <input
                    v-model="form.primary_locale"
                    type="text"
                    class="form-input w-full"
                    placeholder="e.g. fr"
                />
                <p v-if="form.errors.primary_locale" class="text-sm text-red-400 mt-1">{{ form.errors.primary_locale }}</p>
            </div>
        </section>

        <!-- ==================== SEO PROPERTIES ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">SEO Properties</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <!-- GSC -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">GSC Property</label>
                    <input
                        v-model="form.gsc_property"
                        type="text"
                        class="form-input w-full font-mono"
                        placeholder="sc-domain:example.com"
                    />
                    <p v-if="form.errors.gsc_property" class="text-sm text-red-400 mt-1">{{ form.errors.gsc_property }}</p>
                </div>

                <!-- Bing -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Bing Webmaster URL</label>
                    <input
                        v-model="form.bing_url"
                        type="text"
                        class="form-input w-full font-mono"
                        placeholder="https://example.com"
                    />
                    <p v-if="form.errors.bing_url" class="text-sm text-red-400 mt-1">{{ form.errors.bing_url }}</p>
                </div>

                <!-- GA4 -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">GA4 Property</label>
                    <input
                        v-model="form.ga4_property"
                        type="text"
                        class="form-input w-full font-mono"
                        placeholder="p123456789"
                    />
                    <p v-if="form.errors.ga4_property" class="text-sm text-red-400 mt-1">{{ form.errors.ga4_property }}</p>
                </div>

                <!-- Sitemap -->
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Sitemap Path</label>
                    <input
                        v-model="form.sitemap_path"
                        type="text"
                        class="form-input w-full font-mono"
                        placeholder="/sitemap.xml"
                    />
                    <p v-if="form.errors.sitemap_path" class="text-sm text-red-400 mt-1">{{ form.errors.sitemap_path }}</p>
                </div>
            </div>

            <!-- Sitemap locale pattern -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Sitemap Locale Pattern <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <input
                    v-model="form.sitemap_locale_pattern"
                    type="text"
                    class="form-input w-full font-mono"
                    placeholder="/sitemap-{locale}.xml"
                />
                <p class="text-xs text-zinc-500 mt-1">Use <code class="text-zinc-400 bg-white/5 px-1 rounded">{'{locale}'}</code> as placeholder.</p>
                <p v-if="form.errors.sitemap_locale_pattern" class="text-sm text-red-400 mt-1">{{ form.errors.sitemap_locale_pattern }}</p>
            </div>
        </section>

        <!-- ==================== PAGES & REVENUE ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">Pages &amp; Revenue</h2>

            <!-- Key pages -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Key Pages <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <div class="space-y-2 mb-3">
                    <div
                        v-for="(page, i) in form.key_pages"
                        :key="i"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10"
                    >
                        <span class="flex-1 text-sm font-mono text-zinc-300 truncate">{{ page }}</span>
                        <button
                            type="button"
                            @click="removeItem(form.key_pages, i)"
                            class="shrink-0 text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove key page ${page}`"
                        >
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newKeyPage"
                        type="text"
                        class="form-input flex-1 font-mono"
                        placeholder="/blog/my-article"
                        @keydown="handleAddKeyDown($event, form.key_pages, newKeyPage, () => (newKeyPage = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.key_pages, newKeyPage, () => (newKeyPage = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.key_pages" class="text-sm text-red-400 mt-1">{{ form.errors.key_pages }}</p>
            </div>

            <!-- Ad networks -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Ad Networks <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <div class="flex flex-wrap gap-2 mb-3">
                    <span
                        v-for="(net, i) in form.ad_networks"
                        :key="i"
                        class="inline-flex items-center gap-1.5 px-2 py-1 rounded-md bg-white/5 border border-white/10 text-xs text-zinc-300"
                    >
                        {{ net }}
                        <button
                            type="button"
                            @click="removeItem(form.ad_networks, i)"
                            class="text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove ad network ${net}`"
                        >
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </span>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newAdNetwork"
                        type="text"
                        class="form-input flex-1"
                        placeholder="adsense, mediavine…"
                        @keydown="handleAddKeyDown($event, form.ad_networks, newAdNetwork, () => (newAdNetwork = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.ad_networks, newAdNetwork, () => (newAdNetwork = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.ad_networks" class="text-sm text-red-400 mt-1">{{ form.errors.ad_networks }}</p>
            </div>

            <!-- Merchant domains -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Merchant Domains <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <div class="space-y-2 mb-3">
                    <div
                        v-for="(merchant, i) in form.merchant_domains"
                        :key="i"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10"
                    >
                        <span class="flex-1 text-sm font-mono text-zinc-300 truncate">{{ merchant }}</span>
                        <button
                            type="button"
                            @click="removeItem(form.merchant_domains, i)"
                            class="shrink-0 text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove merchant domain ${merchant}`"
                        >
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newMerchantDomain"
                        type="text"
                        class="form-input flex-1 font-mono"
                        placeholder="amazon.fr"
                        @keydown="handleAddKeyDown($event, form.merchant_domains, newMerchantDomain, () => (newMerchantDomain = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.merchant_domains, newMerchantDomain, () => (newMerchantDomain = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.merchant_domains" class="text-sm text-red-400 mt-1">{{ form.errors.merchant_domains }}</p>
            </div>

            <!-- Amazon tag -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">
                    Amazon Tag <span class="text-zinc-500 font-normal">(optional)</span>
                </label>
                <input
                    v-model="form.amazon_tag"
                    type="text"
                    class="form-input w-full font-mono"
                    placeholder="examplestore-21"
                />
                <p class="text-xs text-zinc-500 mt-0.5">Amazon Associates tag of reference. Leave empty to auto-detect the dominant tag.</p>
                <p v-if="form.errors.amazon_tag" class="text-sm text-red-400 mt-1">{{ form.errors.amazon_tag }}</p>
            </div>
        </section>

        <!-- ==================== INFRASTRUCTURE ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">Infrastructure</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        Dokploy App ID <span class="text-zinc-500 font-normal">(optional)</span>
                    </label>
                    <input
                        v-model="form.dokploy_app_id"
                        type="text"
                        class="form-input w-full font-mono"
                        placeholder="app-xxx"
                    />
                    <p v-if="form.errors.dokploy_app_id" class="text-sm text-red-400 mt-1">{{ form.errors.dokploy_app_id }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-white mb-2">
                        Dokploy Resource Type <span class="text-zinc-500 font-normal">(optional)</span>
                    </label>
                    <select v-model="form.dokploy_resource_type" class="form-input w-full">
                        <option value="">— none —</option>
                        <option v-for="rt in dokployResourceTypes" :key="rt.value" :value="rt.value">{{ rt.label }}</option>
                    </select>
                    <p v-if="form.errors.dokploy_resource_type" class="text-sm text-red-400 mt-1">{{ form.errors.dokploy_resource_type }}</p>
                </div>
            </div>
        </section>

        <!-- ==================== RAPPORT AUTOMATIQUE ==================== -->
        <section class="glass p-6 space-y-5">
            <h2 class="text-sm font-semibold text-zinc-400 uppercase tracking-wider">Rapport automatique</h2>

            <!-- Frequency -->
            <div>
                <label class="block text-sm font-medium text-white mb-2">Fréquence</label>
                <div class="grid grid-cols-3 gap-2">
                    <div
                        v-for="f in reportFrequencies"
                        :key="f.value"
                        @click="form.report_frequency = f.value"
                        :class="[
                            'p-3 rounded-lg cursor-pointer transition-colors border text-center text-sm font-medium',
                            form.report_frequency === f.value
                                ? 'bg-emerald-500/20 border-emerald-500/30 text-emerald-400'
                                : 'bg-white/5 border-white/10 text-zinc-400 hover:bg-white/10 hover:text-white'
                        ]"
                        role="radio"
                        :aria-checked="form.report_frequency === f.value"
                        tabindex="0"
                        @keydown.enter.prevent="form.report_frequency = f.value"
                        @keydown.space.prevent="form.report_frequency = f.value"
                    >
                        {{ f.label }}
                    </div>
                </div>
                <p v-if="form.errors.report_frequency" class="text-sm text-red-400 mt-1">{{ form.errors.report_frequency }}</p>
            </div>

            <!-- Recipients -->
            <div v-if="form.report_frequency !== 'none'">
                <label class="block text-sm font-medium text-white mb-2">
                    Destinataires <span class="text-zinc-500 font-normal">(optionnel)</span>
                </label>
                <p class="text-xs text-zinc-500 mb-3">
                    Laisser vide pour utiliser l'adresse par défaut configurée sur le serveur.
                </p>
                <div class="space-y-2 mb-3">
                    <div
                        v-for="(recipient, i) in form.report_recipients"
                        :key="i"
                        class="flex items-center gap-2 px-3 py-2 rounded-lg bg-white/5 border border-white/10"
                    >
                        <span class="flex-1 text-sm font-mono text-zinc-300 truncate">{{ recipient }}</span>
                        <button
                            type="button"
                            @click="removeItem(form.report_recipients, i)"
                            class="shrink-0 text-zinc-600 hover:text-red-400 transition-colors"
                            :aria-label="`Remove recipient ${recipient}`"
                        >
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="flex gap-2">
                    <input
                        v-model="newReportRecipient"
                        type="email"
                        class="form-input flex-1 font-mono"
                        placeholder="contact@example.com"
                        @keydown="handleAddKeyDown($event, form.report_recipients, newReportRecipient, () => (newReportRecipient = ''))"
                    />
                    <button
                        type="button"
                        @click="addItem(form.report_recipients, newReportRecipient, () => (newReportRecipient = ''))"
                        class="px-3 py-2 rounded-lg bg-white/5 border border-white/10 text-zinc-400 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium shrink-0"
                    >
                        Add
                    </button>
                </div>
                <p v-if="form.errors.report_recipients" class="text-sm text-red-400 mt-1">{{ form.errors.report_recipients }}</p>
            </div>
        </section>

        <!-- ==================== SUBMIT ==================== -->
        <button
            type="submit"
            :disabled="form.processing"
            class="btn-primary w-full py-3 px-4 disabled:opacity-50"
        >
            {{ form.processing ? 'Saving…' : (submitLabel ?? 'Save') }}
        </button>
    </form>
</template>
