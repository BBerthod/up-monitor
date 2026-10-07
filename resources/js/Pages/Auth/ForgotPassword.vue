<script setup lang="ts">
import { Head, useForm, Link, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import GuestLayout from '@/Layouts/GuestLayout.vue'

defineOptions({ layout: GuestLayout })

const status = computed(() => (usePage().props as any).status)

const form = useForm({
    email: '',
})

const submit = () => {
    form.post(route('password.email'))
}
</script>

<template>
    <Head title="Forgot Password" />

    <div class="mb-6">
        <h1 class="text-base font-semibold text-zinc-100 mb-1">Forgot Password</h1>
        <p class="text-zinc-400 text-sm">Enter your email and we'll send you a reset link.</p>
    </div>

    <div v-if="status" role="status" class="mb-6 p-3 rounded-md border border-emerald-500/30 bg-emerald-500/10 text-emerald-300 text-sm">
        {{ status }}
    </div>

    <form @submit.prevent="submit" class="space-y-6">
        <div>
            <label for="email" class="block text-sm font-medium text-zinc-300 mb-1">Email</label>
            <input id="email" type="email" v-model="form.email" required autofocus autocomplete="email" class="form-input" placeholder="you@example.com" />
            <p v-if="form.errors.email" class="mt-2 text-sm text-red-400">{{ form.errors.email }}</p>
        </div>

        <button type="submit" :disabled="form.processing" class="btn-primary w-full">
            {{ form.processing ? 'Sending...' : 'Send Reset Link' }}
        </button>
    </form>

    <div class="mt-6 text-center">
        <Link :href="route('login')" class="text-sm text-zinc-400 underline underline-offset-2 decoration-zinc-700 hover:text-zinc-100 transition-colors">Back to login</Link>
    </div>
</template>
