import {DatePipe} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, effect, inject, OnInit, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {PasswordHint, passwordValidator} from '@app/core/validators/password.validator';
import {McpClient} from '@app/models/mcp-client';
import {AlertService} from '@app/services/alert.service';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';
import {OAuthService} from '@app/services/oauth.service';

@Component({
    selector: 'fd-account',
    imports: [DatePipe, ReactiveFormsModule],
    templateUrl: './account.component.html',
    styleUrl: './account.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class AccountComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    private readonly auth = inject(AuthenticationService);
    private readonly alerts = inject(AlertService);
    private readonly fb = inject(FormBuilder).nonNullable;
    private readonly oauth = inject(OAuthService);

    /** Address MCP clients connect to. */
    protected readonly mcpUrl = `${window.location.origin}/mcp`;
    protected readonly mcpClients = signal<McpClient[]>([]);

    protected readonly passwordHint = PasswordHint;
    protected readonly savingProfile = signal(false);
    protected readonly savingPassword = signal(false);
    protected readonly passwordError = signal<string | null>(null);

    protected readonly profileForm = this.fb.group({
        name: ['', Validators.required],
    });

    protected readonly passwordForm = this.fb.group({
        currentPassword: ['', Validators.required],
        newPassword: ['', [Validators.required, passwordValidator]],
    });

    public constructor() {
        effect(() => {
            const user = this.currentUser.currentUser();
            if (user !== null && this.profileForm.pristine) {
                this.profileForm.setValue({name: user.name});
            }
        });
    }

    public async ngOnInit(): Promise<void> {
        await this.loadClients();
    }

    protected async disconnect(client: McpClient): Promise<void> {
        if (!confirm(`Disconnect ${client.clientName}? It will need to be authorized again.`)) {
            return;
        }
        try {
            await this.oauth.revokeClient(client.clientId);
            this.alerts.success(`${client.clientName} was disconnected.`);
            await this.loadClients();
        } catch {
            // The error interceptor shows the toast.
        }
    }

    protected async saveProfile(): Promise<void> {
        if (this.profileForm.invalid) {
            return;
        }
        this.savingProfile.set(true);
        try {
            await this.currentUser.updateName(this.profileForm.getRawValue().name);
            this.profileForm.markAsPristine();
            this.alerts.success('Profile saved.');
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.savingProfile.set(false);
        }
    }

    protected async changePassword(): Promise<void> {
        if (this.passwordForm.invalid) {
            return;
        }
        this.savingPassword.set(true);
        this.passwordError.set(null);
        try {
            const {currentPassword, newPassword} = this.passwordForm.getRawValue();
            await this.auth.changePassword(currentPassword, newPassword);
            this.passwordForm.reset();
            this.alerts.success('Password changed. Other sessions have been signed out.');
        } catch (e: unknown) {
            this.passwordError.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Could not change the password.');
        } finally {
            this.savingPassword.set(false);
        }
    }

    private async loadClients(): Promise<void> {
        try {
            this.mcpClients.set(await this.oauth.listClients());
        } catch {
            // The error interceptor shows the toast.
        }
    }
}
