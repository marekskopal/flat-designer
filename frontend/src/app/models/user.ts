export type UserRole = 'Admin' | 'User';

export interface User {
    id: number;
    email: string;
    name: string;
    role: UserRole;
    lastLoginAt: string | null;
    createdAt: string;
}

export interface UserUpdate {
    name?: string;
    email?: string;
    role?: UserRole;
}
