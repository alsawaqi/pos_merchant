/**
 * TS mirror of {@link \App\Enums\StaffPosition} +
 * {@link \App\Enums\StaffStatus}. Source of truth lives in the
 * PHP enums — keep both sides in lock-step.
 */

export const StaffPosition = {
    Cashier: 'cashier',
    Waiter: 'waiter',
    Kitchen: 'kitchen',
    Manager: 'manager',
    Supervisor: 'supervisor',
} as const;

export type StaffPositionValue =
    (typeof StaffPosition)[keyof typeof StaffPosition];

export const StaffStatus = {
    Active: 'active',
    Suspended: 'suspended',
    Terminated: 'terminated',
} as const;

export type StaffStatusValue = (typeof StaffStatus)[keyof typeof StaffStatus];
