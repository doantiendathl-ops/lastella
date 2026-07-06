export const bookingStatusLabels = {
    DRAFT: 'Nháp',
    PENDING_ASSIGNMENT: 'Chờ phân phòng',
    PARTIALLY_ASSIGNED: 'Đã phân một phần',
    FULLY_ASSIGNED: 'Đã phân đủ',
    HELD: 'Đang giữ',
    DEPOSITED: 'Đã đặt cọc',
    PARTIALLY_CHECKED_IN: 'Đã nhận phòng một phần',
    CHECKED_IN: 'Đã nhận phòng',
    PARTIALLY_CHECKED_OUT: 'Đã trả phòng một phần',
    CHECKED_OUT: 'Đã trả phòng',
    CANCELLED: 'Đã hủy',
    NO_SHOW: 'Không đến',
};

export const bookingTypeLabels = {
    OVERNIGHT: 'Qua đêm',
    DAY_USE: 'Sử dụng trong ngày',
    HOURLY: 'Theo giờ',
    EARLY_CHECKIN: 'Nhận phòng sớm',
    LATE_CHECKOUT: 'Trả phòng muộn',
};

export const customerTypeLabels = {
    INDIVIDUAL: 'Khách lẻ',
    GROUP: 'Khách đoàn',
    COMPANY: 'Công ty',
    TOUR: 'Tour',
    WALK_IN: 'Khách vãng lai',
};

export const priceSourceLabels = {
    RATE_TABLE: 'Bảng giá',
    MANUAL: 'Nhập tay',
    SPECIAL_DEAL: 'Ưu đãi riêng',
};

export const paymentTypeLabels = {
    DEPOSIT: 'Đặt cọc',
    ADDITIONAL_DEPOSIT: 'Đặt cọc bổ sung',
    ROOM_PAYMENT: 'Thanh toán phòng',
    SERVICE_PAYMENT: 'Thanh toán dịch vụ',
    REFUND: 'Hoàn tiền',
    ADJUSTMENT: 'Điều chỉnh',
};

export const paymentMethodLabels = {
    CASH: 'Tiền mặt',
    BANK_TRANSFER: 'Chuyển khoản',
    CARD: 'Thẻ',
    E_WALLET: 'Ví điện tử',
    OTHER: 'Khác',
    cash: 'Tiền mặt',
    transfer: 'Chuyển khoản',
};

export const assignmentStatusLabels = {
    ASSIGNED: 'Đã phân phòng',
    RELEASED: 'Đã giải phóng',
    CHECKED_IN: 'Đã nhận phòng',
    CHECKED_OUT: 'Đã trả phòng',
    CANCELLED: 'Đã hủy',
    NO_SHOW: 'Không đến',
};

export const stayStatusLabels = {
    RESERVED: 'Đã đặt',
    CHECKED_IN: 'Đã nhận phòng',
    CHECKED_OUT: 'Đã trả phòng',
    CANCELLED: 'Đã hủy',
    NO_SHOW: 'Không đến',
};

export const roomStatusLabels = {
    VACANT_CLEAN: 'Trống sạch',
    VACANT_DIRTY: 'Trống bẩn',
    OCCUPIED: 'Đang ở',
    RESERVED: 'Đã đặt',
    OUT_OF_ORDER: 'Hỏng',
    OUT_OF_SERVICE: 'Ngừng phục vụ',
    CLEANING: 'Đang dọn',
    INSPECTED: 'Đã kiểm tra',
};

export const cleaningPriorityLabels = {
    EMERGENCY: 'Khẩn cấp',
    HIGH: 'Cao',
    NORMAL: 'Thường',
    LOW: 'Thấp',
};

export const cleaningReasonLabels = {
    CHECKOUT: 'Trả phòng',
    STAYOVER: 'Dọn trong kỳ lưu trú',
    VIP: 'VIP',
    MAINTENANCE: 'Sau bảo trì',
    DEEP_CLEANING: 'Vệ sinh sâu',
    EARLY_CHECKIN: 'Nhận phòng sớm',
    SPECIAL_REQUEST: 'Yêu cầu đặc biệt',
};

export const housekeepingAssignmentStatusLabels = {
    pending: 'Chờ thực hiện',
    in_progress: 'Đang dọn',
    done: 'Đã xong',
    cancelled: 'Đã hủy',
};

export const inspectionResultLabels = {
    pass: 'Đạt',
    fail: 'Không đạt',
    skip: 'Bỏ qua kiểm tra',
};

const maps = {
    bookingStatus: bookingStatusLabels,
    bookingType: bookingTypeLabels,
    customerType: customerTypeLabels,
    priceSource: priceSourceLabels,
    paymentType: paymentTypeLabels,
    paymentMethod: paymentMethodLabels,
    assignmentStatus: assignmentStatusLabels,
    stayStatus: stayStatusLabels,
    roomStatus: roomStatusLabels,
    cleaningPriority: cleaningPriorityLabels,
    cleaningReason: cleaningReasonLabels,
    housekeepingAssignmentStatus: housekeepingAssignmentStatusLabels,
    inspectionResult: inspectionResultLabels,
};

export const labelFor = (type, value) => maps[type]?.[value] ?? value ?? '';

export const localizedOptions = (type, options = []) => options.map((option) => ({
    ...option,
    label: labelFor(type, option.value),
}));
