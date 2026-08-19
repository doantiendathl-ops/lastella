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
    MANUAL: 'Thao tác thủ công',
};

export const housekeepingAssignmentStatusLabels = {
    pending: 'Chờ thực hiện',
    in_progress: 'Đang dọn',
    done: 'Đã xong',
    cancelled: 'Đã hủy',
};

export const cleaningStatusLabels = {
    CLEAN: 'Sạch',
    DIRTY: 'Bẩn',
};

export const inspectionResultLabels = {
    pass: 'Đạt',
    fail: 'Không đạt',
    skip: 'Bỏ qua kiểm tra',
};

// User request (2026-08-19 chat) — the Quyền (Permissions) screen showed
// raw dot-notation slugs (e.g. "stay.checkin"), unreadable for non-technical
// staff assigning roles. Display-only translation: the underlying
// `permissions.name` slug in the DB is UNCHANGED (every $user->can('...')
// check in the backend still uses the English slug) — this only swaps what
// renders on screen. Keep this list in sync with
// database/seeders/RolePermissionSeeder::PERMISSIONS; an unmapped slug
// falls back to showing itself raw (labelFor()'s default), so a missed
// entry here is visible, never silently blank.
export const permissionLabels = {
    'booking.create': 'Tạo đặt phòng',
    'booking.update': 'Sửa đặt phòng',
    'booking.cancel': 'Hủy đặt phòng',
    'booking.restore': 'Khôi phục đặt phòng đã hủy',
    'booking.edit_closed': 'Sửa đặt phòng đã đóng/hủy/không đến',
    'booking.package.manage': 'Quản lý gói dịch vụ trên đặt phòng',
    'room.assign': 'Phân phòng',
    'room.unassign': 'Gỡ phân phòng',
    'room_availability.view': 'Xem tình trạng phòng trống',
    'stay.checkin': 'Nhận phòng',
    'stay.checkout': 'Trả phòng',
    'stay.extend': 'Gia hạn lưu trú',
    'stay.room_move': 'Đổi phòng khi đang lưu trú',
    'stay.actual_time.manage': 'Sửa giờ nhận/trả phòng thực tế',
    'payment.create': 'Thêm thanh toán/đặt cọc',
    'payment.delete': 'Xóa thanh toán (trong ngày)',
    'payment.delete_any_date': 'Xóa thanh toán (mọi ngày)',
    'folio.view': 'Xem hóa đơn',
    'folio.close': 'Đóng hóa đơn',
    'folio.reopen': 'Mở lại hóa đơn đã đóng',
    'charge.create': 'Thêm phí phát sinh',
    'charge.void': 'Hủy phí phát sinh (trong ngày)',
    'charge.void_any_date': 'Hủy phí phát sinh (mọi ngày)',
    'report.view': 'Xem báo cáo',
    'settings.manage': 'Quản lý cài đặt hệ thống',
    'hotel_settings.manage': 'Quản lý cài đặt khách sạn',
    'service_rates.manage': 'Quản lý phụ phí hệ thống',
    'service_packages.manage': 'Quản lý gói dịch vụ (cũ)',
    'services.manage': 'Quản lý Dịch vụ & Yêu cầu',
    'night_audit.run': 'Chạy Night Audit',
    'night_audit.view': 'Xem Night Audit',
    'revenue.view': 'Xem doanh thu',
    'reconciliation.view': 'Xem đối soát',
    'users.manage': 'Quản lý người dùng',
    'roles.manage': 'Quản lý vai trò & quyền',
    'rooms.manage': 'Quản lý phòng',
    'room_types.manage': 'Quản lý loại phòng',
    'rates.manage': 'Quản lý giá phòng',
    'special_request.create': 'Tạo yêu cầu đặc biệt',
    'special_request.fulfill': 'Hoàn thành yêu cầu đặc biệt',
    'special_request.cancel': 'Hủy yêu cầu đặc biệt',
    'housekeeping.view': 'Xem dọn phòng',
    'housekeeping.assign': 'Phân công dọn phòng',
    'room.status.update': 'Cập nhật trạng thái phòng',
    'room.cleaning.update': 'Cập nhật tình trạng dọn phòng',
    'room.inspect': 'Kiểm tra phòng',
    'room.maintenance': 'Đánh dấu phòng bảo trì',
    'rooms.bulk_update': 'Cập nhật hàng loạt phòng',
    'product_services.manage': 'Quản lý Sản phẩm/Dịch vụ (cũ)',
    'checkout_inspection.view': 'Xem kiểm đồ trả phòng',
    'checkout_inspection.perform': 'Thực hiện kiểm đồ trả phòng',
    'checkout_inspection.override': 'Bỏ qua kiểm đồ trả phòng',
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
    cleaningStatus: cleaningStatusLabels,
    permission: permissionLabels,
};

export const labelFor = (type, value) => maps[type]?.[value] ?? value ?? '';

export const localizedOptions = (type, options = []) => options.map((option) => ({
    ...option,
    label: labelFor(type, option.value),
}));
