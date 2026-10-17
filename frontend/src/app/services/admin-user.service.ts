import {HttpClient} from '@angular/common/http';
import {inject, Injectable} from '@angular/core';
import {User, UserUpdate} from '@app/models/user';
import {environment} from '@environments/environment';
import {firstValueFrom} from 'rxjs';

@Injectable({providedIn: 'root'})
export class AdminUserService {
    private readonly http = inject(HttpClient);

    public async list(): Promise<User[]> {
        return firstValueFrom(this.http.get<User[]>(`${environment.apiUrl}/admin/users`));
    }

    public async update(userId: number, update: UserUpdate): Promise<User> {
        return firstValueFrom(this.http.patch<User>(`${environment.apiUrl}/admin/users/${userId}`, update));
    }

    public async delete(userId: number): Promise<void> {
        await firstValueFrom(this.http.delete<void>(`${environment.apiUrl}/admin/users/${userId}`));
    }
}
