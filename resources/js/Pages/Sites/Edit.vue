<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'
import SiteForm from '@/Components/SiteForm.vue'

interface Site {
    id: number
    alias: string
    organization: string | null
    primary_domain: string
    domains: string[] | null
    locales: string[] | null
    primary_locale: string | null
    type: string
    health_endpoint: string | null
    gsc_property: string | null
    bing_url: string | null
    ga4_property: string | null
    sitemap_path: string | null
    sitemap_locale_pattern: string | null
    key_pages: string[] | null
    ad_networks: string[] | null
    merchant_domains: string[] | null
    amazon_tag: string | null
    dokploy_app_id: string | null
    dokploy_resource_type: string | null
    is_active: boolean
    report_frequency: string
    report_recipients: string[] | null
}

const props = defineProps<{
    site: Site
}>()

const form = useForm({
    alias: props.site.alias,
    organization: props.site.organization ?? '',
    primary_domain: props.site.primary_domain,
    domains: [...(props.site.domains ?? [])],
    locales: [...(props.site.locales ?? [])],
    primary_locale: props.site.primary_locale ?? '',
    type: props.site.type,
    health_endpoint: props.site.health_endpoint ?? '',
    gsc_property: props.site.gsc_property ?? '',
    bing_url: props.site.bing_url ?? '',
    ga4_property: props.site.ga4_property ?? '',
    sitemap_path: props.site.sitemap_path ?? '',
    sitemap_locale_pattern: props.site.sitemap_locale_pattern ?? '',
    key_pages: [...(props.site.key_pages ?? [])],
    ad_networks: [...(props.site.ad_networks ?? [])],
    merchant_domains: [...(props.site.merchant_domains ?? [])],
    amazon_tag: props.site.amazon_tag ?? '',
    dokploy_app_id: props.site.dokploy_app_id ?? '',
    dokploy_resource_type: props.site.dokploy_resource_type ?? '',
    is_active: props.site.is_active,
    report_frequency: props.site.report_frequency ?? 'none',
    report_recipients: [...(props.site.report_recipients ?? [])],
})

const submit = () => form.put(route('sites.update', props.site.id))
</script>

<template>
    <Head :title="`Edit ${site.alias}`" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('sites.index')" label="Back to Sites" />
        <PageHeader
            :title="`Edit ${site.alias}`"
            :description="`Update settings for ${site.primary_domain}.`"
        />

        <SiteForm :form="form" submit-label="Update Site" @submit="submit" />
    </div>
</template>
