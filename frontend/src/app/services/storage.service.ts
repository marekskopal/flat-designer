import {Injectable} from '@angular/core';

/** JSON values in localStorage; unreadable entries count as missing. */
@Injectable({providedIn: 'root'})
export class StorageService {
    public get<T>(key: string): T | null {
        try {
            const raw = localStorage.getItem(key);
            return raw === null ? null : (JSON.parse(raw) as T);
        } catch {
            return null;
        }
    }

    public set(key: string, value: unknown): void {
        localStorage.setItem(key, JSON.stringify(value));
    }

    public remove(key: string): void {
        localStorage.removeItem(key);
    }
}
