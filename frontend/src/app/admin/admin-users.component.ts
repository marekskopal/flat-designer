import {DatePipe} from '@angular/common';
import {ChangeDetectionStrategy, Component, inject, OnInit, signal} from '@angular/core';
import {User, UserRole} from '@app/models/user';
import {AdminUserService} from '@app/services/admin-user.service';
import {AlertService} from '@app/services/alert.service';
import {CurrentUserService} from '@app/services/current-user.service';

/** Admin overview of all accounts: change role, delete. */
@Component({
    selector: 'fd-admin-users',
    imports: [DatePipe],
    templateUrl: './admin-users.component.html',
    styleUrl: './admin-users.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AdminUsersComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    private readonly adminUsers = inject(AdminUserService);
    private readonly alerts = inject(AlertService);

    protected readonly roles: UserRole[] = ['User', 'Admin'];
    protected readonly users = signal<User[]>([]);
    protected readonly loading = signal(true);

    public async ngOnInit(): Promise<void> {
        try {
            this.users.set(await this.adminUsers.list());
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.loading.set(false);
        }
    }

    protected async changeRole(user: User, event: Event): Promise<void> {
        const select = event.target as HTMLSelectElement;
        try {
            this.replace(await this.adminUsers.update(user.id, {role: select.value as UserRole}));
            this.alerts.success(`${user.name} is now ${select.value === 'Admin' ? 'an admin' : 'a user'}.`);
        } catch {
            select.value = user.role;
        }
    }

    protected async delete(user: User): Promise<void> {
        if (!confirm(`Delete ${user.name} (${user.email})? This cannot be undone.`)) {
            return;
        }
        try {
            await this.adminUsers.delete(user.id);
            this.users.update((all) => all.filter((u) => u.id !== user.id));
            this.alerts.success(`${user.name} was deleted.`);
        } catch {
            // The error interceptor shows the toast.
        }
    }

    private replace(updated: User): void {
        this.users.update((all) => all.map((u) => (u.id === updated.id ? updated : u)));
    }
}
