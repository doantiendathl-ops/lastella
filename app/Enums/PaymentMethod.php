<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'CASH';
    case BankTransfer = 'BANK_TRANSFER';
    case Card = 'CARD';
    case EWallet = 'E_WALLET';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tiền mặt',
            self::BankTransfer => 'Chuyển khoản',
            self::Card => 'Thẻ',
            self::EWallet => 'Ví điện tử',
            self::Other => 'Khác',
        };
    }

    public static function options(): array
    {
        return array_map(
            fn (self $method): array => ['value' => $method->value, 'label' => $method->label()],
            self::cases(),
        );
    }
}
