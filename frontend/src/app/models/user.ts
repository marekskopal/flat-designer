import {Locale} from '@app/core/locale';
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
    /** The language of the application and of the account's mail. */
    locale: Locale;
}

/** What a user changes on their own account (`PATCH /api/current-user`). */
export interface CurrentUserUpdate {
    name?: string;
    electricalSymbols?: ElectricalSymbols;
    locale?: Locale;
}

export interface UserUpdate {
    name?: string;
    email?: string;
    role?: UserRole;
    /** A new password, which signs the user out everywhere (not one's own: the account page changes that). */
    password?: string;
}

/** An account an admin adds; its email counts as verified. */
export interface UserCreate {
    name: string;
    email: string;
    password: string;
    role: UserRole;
    /** Missing: English. */
    locale?: Locale;
}
