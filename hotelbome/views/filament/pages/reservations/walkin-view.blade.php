<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modern Registration Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.3/cdn.min.js" defer></script>
    <style>
        /* Sedikit kustomisasi untuk scrollbar dan tampilan input number */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #888; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #555; }
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button {
          -webkit-appearance: none;
          margin: 0;
        }
        input[type=number] { -moz-appearance: textfield; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800" x-data="walkinForm()" x-init="init()">

    <div class="container mx-auto p-4 lg:p-8">

        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-900">Entry Registration Form</h1>
            <p class="text-gray-600">Walk-In No: <span class="font-semibold text-blue-600" x-text="registerNo"></span></p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 lg:gap-8">

            <div class="lg:col-span-2 space-y-8">

                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-semibold mb-4 border-b pb-2">Guest Information</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div class="sm:col-span-2">
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <div class="flex items-center gap-2">
                                <select id="name" x-model="form.reserved_title" class="block w-20 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option>MR</option><option>MRS</option><option>MS</option>
                                </select>
                                <input type="text" x-model="form.reserved_by" placeholder="First Name" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <input type="text" placeholder="Last Name" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <label for="address" class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                            <input type="text" id="address" x-model="form.address" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="city_country" class="block text-sm font-medium text-gray-700 mb-1">City/Country</label>
                            <input type="text" id="city_country" x-model="form.city_country" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="nationality" class="block text-sm font-medium text-gray-700 mb-1">Nationality</label>
                            <input type="text" id="nationality" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="id_no" class="block text-sm font-medium text-gray-700 mb-1">ID Number</label>
                            <div class="flex items-center gap-2">
                                <select id="id_no" x-model="form.id_type" class="block w-32 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option>KTP</option><option>PASSPORT</option><option>SIM</option>
                                </select>
                                <input type="text" x-model="form.id_no" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-semibold mb-4 border-b pb-2">Room & Rate</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <label for="room_no" class="block text-sm font-medium text-gray-700 mb-1">Room No</label>
                            <select id="room_no" x-model="form.room_id" @change="applyRoomRate()" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="">Choose...</option>
                                <template x-for="room in rooms" :key="room.id">
                                    <option :value="room.id" x-text="room.label"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label for="extra_bed" class="block text-sm font-medium text-gray-700 mb-1">Extra Bed (Rp)</label>
                            <input type="number" id="extra_bed" x-model.number="form.extra_bed_rp" @input.debounce="recalc()" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="mt-6 border-t pt-4">
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Room Rate / night</span>
                                <input type="number" x-model.number="form.room_rate" @input.debounce="recalc()" class="w-32 text-right rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Discount (%)</span>
                                <input type="number" x-model.number="form.discount_percent" @input.debounce="recalc()" class="w-32 text-right rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <hr class="my-2">
                             <div class="flex justify-between items-center font-medium">
                                <span>Basic Rate</span>
                                <span x-text="`Rp ${formatNumber(sum.basic)}`"></span>
                            </div>
                            <div class="flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                  <input type="number" x-model.number="form.service_percent" @input.debounce="recalc()" class="w-16 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                  <span>% Service</span>
                                </div>
                                <span x-text="`Rp ${formatNumber(sum.service)}`"></span>
                            </div>
                             <div class="flex justify-between items-center">
                                <div class="flex items-center gap-2">
                                  <input type="number" x-model.number="form.tax_percent" @input.debounce="recalc()" class="w-16 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                  <span>% Tax</span>
                                </div>
                                <span x-text="`Rp ${formatNumber(sum.tax)}`"></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="lg:col-span-1">
                <div class="sticky top-8 space-y-8">
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-semibold mb-4 border-b pb-2">Stay Details</h2>
                        <div class="space-y-4">
                            <div>
                                <label for="arrival" class="block text-sm font-medium text-gray-700 mb-1">Arrival</label>
                                <input type="date" id="arrival" x-model="form.expected_arrival" @change="syncDeparture()" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="departure" class="block text-sm font-medium text-gray-700 mb-1">Departure</label>
                                <input type="date" id="departure" x-model="form.expected_departure" @change="syncNights()" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="length_stay" class="block text-sm font-medium text-gray-700 mb-1">Length of Stay</label>
                                <div class="flex items-center gap-2">
                                    <input type="number" id="length_stay" x-model.number="form.nights" @input.debounce="syncDeparture()" class="block w-20 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <span class="text-gray-600">Night(s)</span>
                                </div>
                            </div>
                            <div>
                                <label for="charge_to" class="block text-sm font-medium text-gray-700 mb-1">Charge To</label>
                                <select id="charge_to" x-model="form.method" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <option value="PERSONAL">Personal Account</option>
                                    <option value="COMPANY">Company</option>
                                </select>
                            </div>
                            <div>
                                <label for="remark" class="block text-sm font-medium text-gray-700 mb-1">Short Remark</label>
                                <textarea id="remark" x-model="form.remark" rows="3" class="block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow-md p-6">
                        <h2 class="text-xl font-semibold mb-4 border-b pb-2">Summary & Actions</h2>
                        <div class="space-y-4">
                            <div class="flex justify-between items-center text-2xl font-bold">
                                <span>Total Balance</span>
                                <span class="text-blue-600" x-text="`Rp ${formatNumber(sum.total)}`"></span>
                            </div>
                            <div class="pt-4 space-y-3">
                                <button @click="save()" class="w-full flex justify-center items-center gap-2 bg-blue-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-blue-700 transition-colors">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M7.707 10.293a1 1 0 10-1.414 1.414l3 3a1 1 0 001.414 0l3-3a1 1 0 00-1.414-1.414L11 11.586V6a1 1 0 10-2 0v5.586L7.707 10.293zM5 3a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2V5a2 2 0 00-2-2H5z" /></svg>
                                    Save
                                </button>
                                <button class="w-full bg-gray-200 text-gray-800 font-bold py-2 px-4 rounded-lg hover:bg-gray-300 transition-colors">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>


<script>
// SCRIPT ALPINE.JS SAMA PERSIS, TIDAK ADA PERUBAHAN LOGIKA
document.addEventListener('alpine:init', () => {
    Alpine.data('walkinForm', () => ({
        registerNo: '04033-092025',
        rooms: [
            { id: 1, label: '101 — Standard', rate: 500000 },
            { id: 2, label: '102 — Deluxe', rate: 750000 },
            { id: 3, label: '103 — Suite', rate: 1000000 }
        ],
        form: {
            reserved_title: 'MR', reserved_by: '', address: '', city_country: '', phone: '', email: '',
            id_type: 'KTP', id_no: '', method: 'PERSONAL', ci_code: 'Walk In', nights: 1,
            expected_arrival: new Date().toISOString().slice(0, 10),
            expected_departure: '', room_id: '', room_rate: 0, discount_percent: 0, extra_bed_rp: 0,
            service_percent: 11, tax_percent: 10, remark: '',
        },
        sum: { basic: 0, service: 0, tax: 0, total: 0 },
        formatNumber(value) {
            return new Intl.NumberFormat('id-ID').format(value || 0);
        },
        applyRoomRate() {
            const room = this.rooms.find(r => r.id == this.form.room_id);
            this.form.room_rate = room ? room.rate : 0;
            this.recalc();
        },
        recalc() {
            const nights = Math.max(1, Number(this.form.nights || 1));
            const rate = Number(this.form.room_rate || 0);
            const extraBed = Number(this.form.extra_bed_rp || 0);
            const discPercent = Math.max(0, Math.min(100, Number(this.form.discount_percent || 0)));

            const totalRoomRate = rate * nights;
            const discountAmount = totalRoomRate * (discPercent / 100);
            const rateAfterDiscount = totalRoomRate - discountAmount;

            const basic = rateAfterDiscount + extraBed;
            const serv = basic * (Number(this.form.service_percent || 0) / 100);
            const tax = (basic + serv) * (Number(this.form.tax_percent || 0) / 100);
            const total = basic + serv + tax;

            this.sum.basic = Math.round(basic);
            this.sum.service = Math.round(serv);
            this.sum.tax = Math.round(tax);
            this.sum.total = Math.round(total);
        },
        syncDeparture() {
            try {
                const arrivalDate = new Date(this.form.expected_arrival);
                arrivalDate.setDate(arrivalDate.getDate() + Math.max(1, Number(this.form.nights || 1)));
                this.form.expected_departure = arrivalDate.toISOString().slice(0, 10);
            } catch {}
            this.recalc();
        },
        syncNights() {
            try {
                const diff = new Date(this.form.expected_departure) - new Date(this.form.expected_arrival);
                const days = Math.ceil(diff / (1000 * 60 * 60 * 24));
                this.form.nights = (days > 0) ? days : 1;
                if(days <= 0) this.syncDeparture();
            } catch {}
            this.recalc();
        },
        save() {
            this.recalc();
            alert('Form data prepared for saving!');
            console.log({ formData: this.form, calculations: this.sum });
        },
        init() {
            this.syncDeparture();
        }
    }));
});
</script>

</body>
</html>
