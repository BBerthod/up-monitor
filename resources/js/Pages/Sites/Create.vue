<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { useForm } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'
import SiteForm from '@/Components/SiteForm.vue'

const form = useForm({
    alias: '',
    organization: '',
    primary_domain: '',
    domains: [] as string[],
    locales: [] as string[],
    primary_locale: '',
    type: 'laravel',
    health_endpoint: '',
    gsc_property: '',
    bing_url: '',
    ga4_property: '',
    sitemap_path: '',
    sitemap_locale_pattern: '',
    key_pages: [] as string[],
    ad_networks: [] as string[],
    merchant_domains: [] as string[],
    amazon_tag: '',
    dokploy_app_id: '',
    dokploy_resource_type: '',
    is_active: true,
    report_frequency: 'none',
    report_recipients: [] as string[],
})

const submit = () => form.post(route('sites.store'))
</script>

<template>
    <Head title="Add Site" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('sites.index')" label="Back to Sites" />
        <PageHeader title="Add Site" description="Register a new website to track its SEO and uptime." />

        <SiteForm :form="form" submit-label="Create Site" @submit="submit" />
    </div>
</template>
