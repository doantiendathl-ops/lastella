<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Banknote, BedDouble, CheckCircle, LogIn, LogOut, Pencil, Plus, Trash2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps({
    booking: { type: Object, required: true },
    activeTab: { type: String, default: 'overview' },
    assignmentSummary: { type: Array, default: () => [] },
    options: { type: Object, required: true },
    can: { type: Object, required: true },
});

const tab = ref(props.activeTab);
const editingRequirementId = ref(null);

const nowLocal = () => {
    const date = new Date();
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
};

const emptyRequirement = () => ({
    room_type_id: props.options.roomTypes[0]?.value ?? '',
    quantity: 1,
    adults: 1,
    children_under_6: 0,
    children_over_6: 0,
    room_price: 0,
    price_source: 'RATE_TABLE',
    note: '',
});

const requirementForm = useForm(emptyRequirement());
const editRequirementForm = useForm(emptyRequirement());
const paymentForm = useForm({
    payment_type: 'DEPOSIT',
    amount: '',
    payment_method: '',
    payment_at: nowLocal(),
    note: '',
});
const assignmentForm = useForm({
    room_id: props.options.rooms[0]?.value ?? '',
    start_at: props.booking.checkin_at,
    end_at: props.booking.checkout_at,
});

const tabs = [
    { key: 'overview', label: 'Overview' },
    { key: 'requirements', label: 'Requirements' },
    { key: 'payments', label: 'Payments / Deposits' },
    { key: 'assignments', label: 'Room Assignments' },
    { key: 'stays', label: 'Stays' },
];

const submitRequirement = () => {
    requirementForm.post(`/admin/bookings/${props.booking.id}/requirements`, {
        preserveScroll: true,
        onSuccess: () => requirementForm.defaults(emptyRequirement()).reset(),
    });
};

const startEditRequirement = (requirement) => {
    editingRequirementId.value = requirement.id;
    editRequirementForm.defaults({
        room_type_id: requirement.room_type_id,
        quantity: requirement.quantity,
        adults: requirement.adults,
        children_under_6: requirement.children_under_6,
        children_over_6: requirement.children_over_6,
        room_price: requirement.room_price,
        price_source: requirement.price_source,
        note: requirement.note ?? '',
    }).reset();
};

const updateRequirement = (requirement) => {
    editRequirementForm.put(`/admin/bookings/${props.booking.id}/requirements/${requirement.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingRequirementId.value = null;
        },
    });
};

const deleteRequirement = (requirement) => {
    if (!window.confirm('Delete this requirement?')) {
        return;
    }

    router.delete(`/admin/bookings/${props.booking.id}/requirements/${requirement.id}`, { preserveScroll: true });
};

const submitPayment = () => {
    paymentForm.post(`/admin/bookings/${props.booking.id}/payments`, {
        preserveScroll: true,
        onSuccess: () => paymentForm.defaults({
            payment_type: 'DEPOSIT',
            amount: '',
            payment_method: '',
            payment_at: nowLocal(),
            note: '',
        }).reset(),
    });
};

const submitAssignment = () => {
    assignmentForm.post(`/admin/bookings/${props.booking.id}/assignments`, {
        preserveScroll: true,
    });
};

const releaseAssignment = (assignment) => {
    const release_reason = window.prompt('Release reason') ?? '';
    router.post(`/admin/bookings/${props.booking.id}/assignments/${assignment.id}/release`, { release_reason }, { preserveScroll: true });
};

const checkIn = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-in`, {}, { preserveScroll: true });
const checkOut = (stay) => router.post(`/admin/bookings/${props.booking.id}/stays/${stay.id}/check-out`, {}, { preserveScroll: true });

const cancelBooking = () => {
    const cancellation_reason = window.prompt('Cancellation reason') ?? '';
    router.post(`/admin/bookings/${props.booking.id}/cancel`, { cancellation_reason }, { preserveScroll: true });
};

const tabClass = (key) => tab.value === key ? 'border-pine text-pine' : 'border-transparent text-steel hover:text-ink';
</script>

<template>
    <Head :title="booking.booking_code" />

    <AppLayout>
        <template #header>
            <div class="flex min-w-0 items-center justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="truncate text-lg font-semibold">{{ booking.booking_code }}</h1>
                    <p class="truncate text-sm text-steel">{{ booking.customer_name }} · {{ booking.status }}</p>
                </div>
                <div class="flex gap-2">
                    <Link v-if="can.editBooking" :href="`/admin/bookings/${booking.id}/edit`" class="inline-flex items-center gap-2 border border-gray-300 bg-white px-3 py-2 text-sm font-semibold text-steel hover:text-ink">
                        <Pencil class="h-4 w-4" />
                        Edit
                    </Link>
                    <button v-if="can.cancelBooking && booking.status !== 'CANCELLED'" type="button" class="inline-flex items-center gap-2 border border-coral px-3 py-2 text-sm font-semibold text-coral hover:bg-coral hover:text-white" @click="cancelBooking">
                        <XCircle class="h-4 w-4" />
                        Cancel
                    </button>
                </div>
            </div>
        </template>

        <section class="border border-gray-200 bg-white shadow-sm">
            <div class="flex overflow-x-auto border-b border-gray-200 px-4">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    class="whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold"
                    :class="tabClass(item.key)"
                    @click="tab = item.key"
                >
                    {{ item.label }}
                </button>
            </div>

            <div v-if="tab === 'overview'" class="grid gap-5 p-5 md:grid-cols-2 xl:grid-cols-3">
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Customer</div>
                    <div class="mt-2 text-sm font-semibold">{{ booking.customer_name }}</div>
                    <div class="mt-1 text-sm text-steel">{{ booking.customer_phone }}</div>
                    <div class="mt-1 text-sm text-steel">{{ booking.customer_email }}</div>
                    <div class="mt-1 text-sm text-steel">{{ booking.customer_type }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Stay</div>
                    <div class="mt-2 text-sm">Type: {{ booking.booking_type }}</div>
                    <div class="mt-1 text-sm">Check-in: {{ booking.checkin_at }}</div>
                    <div class="mt-1 text-sm">Checkout: {{ booking.checkout_at }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Occupancy</div>
                    <div class="mt-2 text-sm">Adults: {{ booking.adults }}</div>
                    <div class="mt-1 text-sm">Children under 6: {{ booking.children_under_6 }}</div>
                    <div class="mt-1 text-sm">Children over 6: {{ booking.children_over_6 }}</div>
                </div>
                <div class="border border-gray-100 p-4">
                    <div class="text-xs uppercase tracking-wide text-steel">Status</div>
                    <div class="mt-2 text-sm font-semibold">{{ booking.status }}</div>
                    <div class="mt-2 inline-flex h-6 w-12 border border-gray-200" :style="{ backgroundColor: booking.booking_color }" />
                    <div class="mt-2 text-sm text-steel">Sales: {{ booking.sales_user ?? 'Unassigned' }}</div>
                </div>
                <div class="border border-gray-100 p-4 md:col-span-2">
                    <div class="text-xs uppercase tracking-wide text-steel">Notes</div>
                    <div class="mt-2 whitespace-pre-line text-sm">{{ booking.note }}</div>
                    <div class="mt-3 whitespace-pre-line text-sm text-steel">{{ booking.internal_note }}</div>
                </div>
            </div>

            <div v-if="tab === 'requirements'" class="space-y-5 p-5">
                <form v-if="can.updateBooking" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-4" @submit.prevent="submitRequirement">
                    <select v-model="requirementForm.room_type_id" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="type in options.roomTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <input v-model="requirementForm.quantity" type="number" min="1" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Quantity">
                    <input v-model="requirementForm.adults" type="number" min="0" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Adults">
                    <input v-model="requirementForm.children_under_6" type="number" min="0" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Children under 6">
                    <input v-model="requirementForm.children_over_6" type="number" min="0" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Children over 6">
                    <input v-model="requirementForm.room_price" type="number" min="0" step="0.01" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Room price">
                    <select v-model="requirementForm.price_source" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="source in options.priceSources" :key="source.value" :value="source.value">{{ source.label }}</option>
                    </select>
                    <input v-model="requirementForm.note" type="text" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Note">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <Plus class="h-4 w-4" />
                        Add Requirement
                    </button>
                    <p v-if="Object.keys(requirementForm.errors).length" class="md:col-span-4 text-sm text-coral">{{ Object.values(requirementForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr>
                                <th class="px-4 py-3">Room Type</th>
                                <th class="px-4 py-3">Quantity</th>
                                <th class="px-4 py-3">Adults</th>
                                <th class="px-4 py-3">Children</th>
                                <th class="px-4 py-3">Room Price</th>
                                <th class="px-4 py-3">Price Source</th>
                                <th class="px-4 py-3">Note</th>
                                <th v-if="can.updateBooking" class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="requirement in booking.requirements" :key="requirement.id">
                                <template v-if="editingRequirementId === requirement.id">
                                    <td class="px-4 py-3"><select v-model="editRequirementForm.room_type_id" class="w-full border border-gray-300 px-2 py-1"><option v-for="type in options.roomTypes" :key="type.value" :value="type.value">{{ type.label }}</option></select></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.quantity" type="number" min="1" class="w-20 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.adults" type="number" min="0" class="w-20 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.children_under_6" type="number" min="0" class="w-20 border border-gray-300 px-2 py-1"> / <input v-model="editRequirementForm.children_over_6" type="number" min="0" class="w-20 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.room_price" type="number" min="0" step="0.01" class="w-28 border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3"><select v-model="editRequirementForm.price_source" class="border border-gray-300 px-2 py-1"><option v-for="source in options.priceSources" :key="source.value" :value="source.value">{{ source.label }}</option></select></td>
                                    <td class="px-4 py-3"><input v-model="editRequirementForm.note" type="text" class="w-full border border-gray-300 px-2 py-1"></td>
                                    <td class="px-4 py-3 text-right">
                                        <button type="button" class="mr-2 bg-pine px-3 py-1 text-xs font-semibold text-white" @click="updateRequirement(requirement)">Save</button>
                                        <button type="button" class="border border-gray-300 px-3 py-1 text-xs" @click="editingRequirementId = null">Cancel</button>
                                    </td>
                                </template>
                                <template v-else>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.room_type }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.quantity }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.adults }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.children_under_6 }} / {{ requirement.children_over_6 }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.room_price }}</td>
                                    <td class="whitespace-nowrap px-4 py-3">{{ requirement.price_source }}</td>
                                    <td class="px-4 py-3">{{ requirement.note }}</td>
                                    <td v-if="can.updateBooking" class="whitespace-nowrap px-4 py-3 text-right">
                                        <button type="button" class="mr-2 inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-pine hover:text-pine" @click="startEditRequirement(requirement)"><Pencil class="h-4 w-4" /></button>
                                        <button type="button" class="inline-flex h-8 w-8 items-center justify-center border border-gray-200 text-steel hover:border-coral hover:text-coral" @click="deleteRequirement(requirement)"><Trash2 class="h-4 w-4" /></button>
                                    </td>
                                </template>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'payments'" class="space-y-5 p-5">
                <form v-if="can.addPayment" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-5" @submit.prevent="submitPayment">
                    <select v-model="paymentForm.payment_type" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="type in options.paymentTypes" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                    <input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Amount">
                    <input v-model="paymentForm.payment_method" type="text" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Method">
                    <input v-model="paymentForm.payment_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <input v-model="paymentForm.note" type="text" class="border border-gray-300 px-3 py-2 text-sm" placeholder="Note">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <Banknote class="h-4 w-4" />
                        Add Payment
                    </button>
                    <p v-if="Object.keys(paymentForm.errors).length" class="md:col-span-5 text-sm text-coral">{{ Object.values(paymentForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr><th class="px-4 py-3">Type</th><th class="px-4 py-3">Amount</th><th class="px-4 py-3">Method</th><th class="px-4 py-3">Payment At</th><th class="px-4 py-3">Confirmed By</th><th class="px-4 py-3">Note</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="payment in booking.payments" :key="payment.id"><td class="px-4 py-3">{{ payment.payment_type }}</td><td class="px-4 py-3">{{ payment.amount }}</td><td class="px-4 py-3">{{ payment.payment_method }}</td><td class="px-4 py-3">{{ payment.payment_at }}</td><td class="px-4 py-3">{{ payment.confirmed_by }}</td><td class="px-4 py-3">{{ payment.note }}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'assignments'" class="space-y-5 p-5">
                <div v-if="assignmentSummary.length" class="grid gap-3 md:grid-cols-3">
                    <div v-for="item in assignmentSummary" :key="item.room_type_id" class="border border-gray-100 p-3 text-sm">
                        <div class="font-semibold">{{ item.room_type_code }}</div>
                        <div class="mt-1 text-steel">Required {{ item.required }} · Assigned {{ item.assigned }} · Remaining {{ item.remaining }}</div>
                    </div>
                </div>

                <form v-if="can.assignRoom" class="grid gap-3 border border-gray-100 p-4 md:grid-cols-4" @submit.prevent="submitAssignment">
                    <select v-model="assignmentForm.room_id" class="border border-gray-300 px-3 py-2 text-sm">
                        <option v-for="room in options.rooms" :key="room.value" :value="room.value">{{ room.label }}</option>
                    </select>
                    <input v-model="assignmentForm.start_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <input v-model="assignmentForm.end_at" type="datetime-local" class="border border-gray-300 px-3 py-2 text-sm">
                    <button type="submit" class="inline-flex items-center justify-center gap-2 bg-pine px-3 py-2 text-sm font-semibold text-white">
                        <BedDouble class="h-4 w-4" />
                        Assign Room
                    </button>
                    <p v-if="Object.keys(assignmentForm.errors).length" class="md:col-span-4 text-sm text-coral">{{ Object.values(assignmentForm.errors)[0] }}</p>
                </form>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                            <tr><th class="px-4 py-3">Room</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Start</th><th class="px-4 py-3">End</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Assigned By</th><th class="px-4 py-3">Released At</th><th class="px-4 py-3">Reason</th><th v-if="can.releaseRoom" class="px-4 py-3 text-right">Actions</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="assignment in booking.assignments" :key="assignment.id"><td class="px-4 py-3">{{ assignment.room_number }}</td><td class="px-4 py-3">{{ assignment.room_type }}</td><td class="px-4 py-3">{{ assignment.start_at }}</td><td class="px-4 py-3">{{ assignment.end_at }}</td><td class="px-4 py-3">{{ assignment.status }}</td><td class="px-4 py-3">{{ assignment.assigned_by }}</td><td class="px-4 py-3">{{ assignment.released_at }}</td><td class="px-4 py-3">{{ assignment.release_reason }}</td><td v-if="can.releaseRoom" class="px-4 py-3 text-right"><button v-if="assignment.can_release" type="button" class="border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-coral hover:text-coral" @click="releaseAssignment(assignment)">Release</button></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="tab === 'stays'" class="overflow-x-auto p-5">
                <table class="min-w-full divide-y divide-gray-200 text-left text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-steel">
                        <tr><th class="px-4 py-3">Room</th><th class="px-4 py-3">Planned Check-in</th><th class="px-4 py-3">Planned Checkout</th><th class="px-4 py-3">Actual Check-in</th><th class="px-4 py-3">Actual Checkout</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="stay in booking.stays" :key="stay.id">
                            <td class="px-4 py-3">{{ stay.room_number }}</td>
                            <td class="px-4 py-3">{{ stay.planned_checkin_at }}</td>
                            <td class="px-4 py-3">{{ stay.planned_checkout_at }}</td>
                            <td class="px-4 py-3">{{ stay.actual_checkin_at }}</td>
                            <td class="px-4 py-3">{{ stay.actual_checkout_at }}</td>
                            <td class="px-4 py-3">{{ stay.status }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button v-if="can.checkIn && stay.status === 'RESERVED'" type="button" class="mr-2 inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkIn(stay)"><LogIn class="h-3.5 w-3.5" /> Check In</button>
                                <button v-if="can.checkOut && stay.status === 'CHECKED_IN'" type="button" class="inline-flex items-center gap-2 border border-gray-300 px-3 py-1 text-xs font-semibold text-steel hover:border-pine hover:text-pine" @click="checkOut(stay)"><LogOut class="h-3.5 w-3.5" /> Check Out</button>
                                <CheckCircle v-if="stay.status === 'CHECKED_OUT'" class="ml-auto h-4 w-4 text-pine" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
