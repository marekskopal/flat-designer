import {ElectricalSymbols} from '@app/models/electrical-schedule';

export type UserRole = 'Admin' | 'User';

export interface User {
    id: number;
    email: string;
    name: string;
    role: UserRole;
    lastLoginAt: string | null;
    createdAt: string;
    /** The symbol set of the electrical plan overlay and PDF. */
    electricalSymbols: ElectricalSymbols;
}

/** What a user changes on their own account (`PATCH /api/current-user`). */
export interface CurrentUserUpdate {
    name?: string;
    electricalSymbols?: ElectricalSymbols;
}

export interface UserUpdate {
    name?: string;
    email?: string;
    role?: UserRole;
}
