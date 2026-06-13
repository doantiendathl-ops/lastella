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

const maps = {
    bookingStatus: bookingStatusLabels,
    bookingType: bookingTypeLabels,
    customerType: customerTypeLabels,
    priceSource: priceSourceLabels,
    paymentType: paymentTypeLabels,
    assignmentStatus: assignmentStatusLabels,
    stayStatus: stayStatusLabels,
};

export const labelFor = (type, value) => maps[type]?.[value] ?? value ?? '';

export const localizedOptions = (type, options = []) => options.map((option) => ({
    ...option,
    label: labelFor(type, option.value),
}));
