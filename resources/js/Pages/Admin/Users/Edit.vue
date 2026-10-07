<script setup lang="ts">
import { ref } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import BackLink from '@/Components/BackLink.vue'
import PageHeader from '@/Components/PageHeader.vue'

interface User {
    id: number
    name: string
    email: string
    role: 'super_admin' | 'admin' | 'member'
    team: { id: number; name: string } | null
}

interface AssignableRole {
    value: string
    label: string
}

const props = defineProps<{
    user: User
    assignableRoles: AssignableRole[]
    canChangeRole: boolean
}>()

const form = useForm({
    name: props.user.name,
    email: props.user.email,
    role: props.user.role,
    password: '',
    password_confirmation: '',
})

const showPassword = ref(false)
const showPasswordConfirmation = ref(false)

const submit = () => {
    form.put(route('admin.users.update', props.user.id), {
        onSuccess: () => form.reset('password', 'password_confirmation'),
    })
}
</script>

<template>
    <Head :title="'Edit ' + user.name" />

    <div class="max-w-2xl mx-auto space-y-6">
        <BackLink :href="route('admin.users.index')" label="Back to Users" />
        <PageHeader title="Edit User" description="Update user information" />

        <div class="glass p-4 mb-6 flex items-center gap-4">
            <div class="w-12 h-12 rounded-full bg-zinc-900 flex items-center justify-center border border-white/10">
                <span class="text-emerald-400 font-bold text-lg">{{ user.name.charAt(0).toUpperCase() }}</span>
            </div>
            <div>
                <h3 class="text-white font-semibold">{{ user.name }}</h3>
                <p class="text-slate-400 text-sm">{{ user.email }}</p>
            </div>
            <div class="ml-auto">
                <span v-if="user.role === 'super_admin'" class="px-2 py-1 rounded bg-transparent text-zinc-400 text-xs font-semibold border border-zinc-800">Super Admin</span>
                <span v-else-if="user.role === 'admin'" class="px-2 py-1 rounded bg-transparent text-zinc-400 text-xs font-semibold border border-zinc-800">Admin</span>
                <span v-else class="px-2 py-1 rounded-lg bg-slate-700/30 text-slate-400 text-xs border border-white/5">Member</span>
            </div>
        </div>

        <form @submit.prevent="submit" class="glass-intense p-8 space-y-6">
            <div>
                <label for="name" class="block text-sm font-medium text-slate-300 mb-1">Full Name</label>
                <input id="name" v-model="form.name" type="text" class="form-input" required autofocus />
                <p v-if="form.errors.name" class="mt-2 text-sm text-red-400">{{ form.errors.name }}</p>
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-300 mb-1">Email Address</label>
                <input id="email" v-model="form.email" type="email" class="form-input" required />
                <p v-if="form.errors.email" class="mt-2 text-sm text-red-400">{{ form.errors.email }}</p>
            </div>

            <div v-if="canChangeRole && assignableRoles.length > 1">
                <label for="role" class="block text-sm font-medium text-slate-300 mb-1">Role</label>
                <select id="role" v-model="form.role" class="form-input">
                    <option v-for="r in assignableRoles" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
                <p v-if="form.errors.role" class="mt-2 text-sm text-red-400">{{ form.errors.role }}</p>
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-300 mb-1">New Password</label>
                <div class="relative">
                    <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" class="form-input pr-10" placeholder="Leave blank to keep current" />
                    <button type="button" @click="showPassword = !showPassword" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-300 transition-colors">
                        <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </button>
                </div>
                <p class="mt-1 text-xs text-slate-500">Leave blank to keep the current password</p>
                <p v-if="form.errors.password" class="mt-2 text-sm text-red-400">{{ form.errors.password }}</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-300 mb-1">Confirm New Password</label>
                <div class="relative">
                    <input id="password_confirmation" v-model="form.password_confirmation" :type="showPasswordConfirmation ? 'text' : 'password'" class="form-input pr-10" placeholder="Confirm new password" />
                    <button type="button" @click="showPasswordConfirmation = !showPasswordConfirmation" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-300 transition-colors">
                        <svg v-if="!showPasswordConfirmation" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12c1.292 4.338 5.31 7.5 10.066 7.5.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
                    </button>
                </div>
            </div>

            <div class="flex items-center justify-end gap-4 pt-4 border-t border-white/10">
                <Link :href="route('admin.users.index')" class="px-4 py-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition-colors">Cancel</Link>
                <button type="submit" :disabled="form.processing" class="btn-primary py-3 px-6 disabled:opacity-50">
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>
        </form>
    </div>
</template>
