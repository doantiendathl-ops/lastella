<?php

declare(strict_types=1);

namespace App\Support;

/**
 * User request (2026-08-19 chat) — Vietnamese display labels for permission
 * slugs, used on both the Quyền (Permissions) list and the Vai trò (Role)
 * edit form's permission multiselect. The underlying `permissions.name`
 * slug in the DB is NEVER translated — every $user->can('...') check in the
 * codebase keeps using the English slug; this is display-only.
 *
 * Mirror of resources/js/Support/vietnameseLabels.js::permissionLabels —
 * kept in sync by hand (PHP backend / JS frontend have no shared runtime).
 * An unmapped slug falls back to showing itself raw, so a missed entry is
 * visible, never silently blank.
 */
class PermissionLabels
{
    public const LABELS = [
        'booking.create' => 'Tạo đặt phòng',
        'booking.update' => 'Sửa đặt phòng',
        'booking.cancel' => 'Hủy đặt phòng',
        'booking.restore' => 'Khôi phục đặt phòng đã hủy',
        'booking.edit_closed' => 'Sửa đặt phòng đã đóng/hủy/không đến',
        'booking.package.manage' => 'Quản lý gói dịch vụ trên đặt phòng',
        'room.assign' => 'Phân phòng',
        'room.unassign' => 'Gỡ phân phòng',
        'room_availability.view' => 'Xem tình trạng phòng trống',
        'stay.checkin' => 'Nhận phòng',
        'stay.checkout' => 'Trả phòng',
        'stay.extend' => 'Gia hạn lưu trú',
        'stay.room_move' => 'Đổi phòng khi đang lưu trú',
        'stay.actual_time.manage' => 'Sửa giờ nhận/trả phòng thực tế',
        'payment.create' => 'Thêm thanh toán/đặt cọc',
        'payment.delete' => 'Xóa thanh toán (trong ngày)',
        'payment.delete_any_date' => 'Xóa thanh toán (mọi ngày)',
        'folio.view' => 'Xem hóa đơn',
        'folio.close' => 'Đóng hóa đơn',
        'folio.reopen' => 'Mở lại hóa đơn đã đóng',
        'charge.create' => 'Thêm phí phát sinh',
        'charge.void' => 'Hủy phí phát sinh (trong ngày)',
        'charge.void_any_date' => 'Hủy phí phát sinh (mọi ngày)',
        'report.view' => 'Xem báo cáo',
        'settings.manage' => 'Quản lý cài đặt hệ thống',
        'hotel_settings.manage' => 'Quản lý cài đặt khách sạn',
        'service_rates.manage' => 'Quản lý phụ phí hệ thống',
        'service_packages.manage' => 'Quản lý gói dịch vụ (cũ)',
        'services.manage' => 'Quản lý Dịch vụ & Yêu cầu',
        'night_audit.run' => 'Chạy Night Audit',
        'night_audit.view' => 'Xem Night Audit',
        'revenue.view' => 'Xem doanh thu',
        'reconciliation.view' => 'Xem đối soát',
        'users.manage' => 'Quản lý người dùng',
        'roles.manage' => 'Quản lý vai trò & quyền',
        'rooms.manage' => 'Quản lý phòng',
        'room_types.manage' => 'Quản lý loại phòng',
        'rates.manage' => 'Quản lý giá phòng',
        'special_request.create' => 'Tạo yêu cầu đặc biệt',
        'special_request.fulfill' => 'Hoàn thành yêu cầu đặc biệt',
        'special_request.cancel' => 'Hủy yêu cầu đặc biệt',
        'housekeeping.view' => 'Xem dọn phòng',
        'housekeeping.assign' => 'Phân công dọn phòng',
        'room.status.update' => 'Cập nhật trạng thái phòng',
        'room.cleaning.update' => 'Cập nhật tình trạng dọn phòng',
        'room.inspect' => 'Kiểm tra phòng',
        'room.maintenance' => 'Đánh dấu phòng bảo trì',
        'rooms.bulk_update' => 'Cập nhật hàng loạt phòng',
        'product_services.manage' => 'Quản lý Sản phẩm/Dịch vụ (cũ)',
        'checkout_inspection.view' => 'Xem kiểm đồ trả phòng',
        'checkout_inspection.perform' => 'Thực hiện kiểm đồ trả phòng',
        'checkout_inspection.override' => 'Bỏ qua kiểm đồ trả phòng',
    ];

    public static function forSlug(string $slug): string
    {
        return self::LABELS[$slug] ?? $slug;
    }
}
