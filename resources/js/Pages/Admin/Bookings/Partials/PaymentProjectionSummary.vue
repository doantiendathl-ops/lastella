<script setup lang="ts">
defineProps<{
    paymentProjection: {
        projected_room_total: number
        posted_non_room_total: number
        expected_total: number
        expected_deposit: number
        recognized_paid_total: number
        expected_balance: number
    }
}>()

const formatCurrency = (value: number) =>
    `${new Intl.NumberFormat('vi-VN', { maximumFractionDigits: 0 }).format(Number(value) || 0)} đ`
</script>

<template>
    <div class="border border-gray-100 bg-gray-50/50 p-4">
        <div class="text-xs font-bold uppercase tracking-wide text-steel">Dự kiến thanh toán</div>

        <div class="mt-3 grid grid-cols-2 gap-2 border-b border-gray-100 pb-3 text-sm md:grid-cols-2">
            <div>
                <div class="text-xs text-steel">Chi phí lưu trú dự kiến</div>
                <div class="mt-1 font-semibold">{{ formatCurrency(paymentProjection.projected_room_total) }}</div>
            </div>
            <div>
                <div class="text-xs text-steel">Phí khác đã ghi nhận</div>
                <div class="mt-1 font-semibold">{{ formatCurrency(paymentProjection.posted_non_room_total) }}</div>
            </div>
        </div>

        <!-- recognized_paid_total is the direct reconciliation counterpart to
             expected_balance (Tổng dự kiến − Đã thanh toán/khấu trừ = Còn dự kiến).
             expected_deposit is a subset of this and is shown only as a secondary
             breakdown below, never as the number expected_balance reconciles against. -->
        <div class="mt-3 grid gap-3 md:grid-cols-3">
            <div>
                <div class="text-xs text-steel">Tổng dự kiến hiện tại</div>
                <div class="mt-1 text-lg font-semibold">{{ formatCurrency(paymentProjection.expected_total) }}</div>
            </div>
            <div>
                <div class="text-xs text-steel">Đã thanh toán/khấu trừ</div>
                <div class="mt-1 text-lg font-semibold">{{ formatCurrency(paymentProjection.recognized_paid_total) }}</div>
            </div>
            <div>
                <div class="text-xs text-steel">Còn dự kiến</div>
                <div
                    class="mt-1 text-lg font-semibold"
                    :class="paymentProjection.expected_balance > 0 ? 'text-coral' : 'text-pine'"
                >
                    {{ formatCurrency(paymentProjection.expected_balance) }}
                </div>
            </div>
        </div>

        <div class="mt-2 text-xs text-steel">
            Trong đó tiền đặt cọc: <span class="font-semibold">{{ formatCurrency(paymentProjection.expected_deposit) }}</span>
        </div>

        <p class="mt-3 text-xs text-steel">
            Số liệu trên gồm chi phí phòng theo thời gian lưu trú hiện tại và các khoản phí khác đã ghi nhận.
            "Đã thanh toán/khấu trừ" gồm đặt cọc, thanh toán, điều chỉnh và đã trừ hoàn tiền — không có nghĩa toàn bộ số tiền đều là tiền mặt đã thu.
            Các dịch vụ định kỳ hoặc phí chưa phát sinh (ăn sáng, giường phụ, thuế lưu trú...) chưa được dự báo.
            Đây không phải hóa đơn cuối cùng và chưa thay thế số liệu đã ghi sổ.
        </p>
    </div>
</template>
