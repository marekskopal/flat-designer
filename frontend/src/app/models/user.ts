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
    /** False for an account that signs in with Google only and has not set a password. */
    hasPassword: boolean;
    /** Linked to a Google account (Sign in with Google). */
    google: boolean;
    /** The address is confirmed (a verification link, Google, or an account from before verification). */
    emailVerified: boolean;
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
