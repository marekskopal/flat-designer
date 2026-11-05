import {NgOptimizedImage} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, inject, signal} from '@angular/core';
import {FormBuilder, ReactiveFormsModule, Validators} from '@angular/forms';
import {RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {AuthenticationService} from '@app/services/authentication.service';
import {LanguageSwitchComponent} from '@app/shared/language-switch/language-switch.component';

@Component({
    selector: 'fd-forgot-password',
    imports: [LanguageSwitchComponent, NgOptimizedImage, ReactiveFormsModule, RouterLink],
    templateUrl: './forgot-password.component.html',
    styleUrl: './auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ForgotPasswordComponent {
    private readonly auth = inject(AuthenticationService);

    protected readonly saving = signal(false);
    protected readonly sentTo = signal<string | null>(null);
    protected readonly error = signal<string | null>(null);
    protected readonly form = inject(FormBuilder).nonNullable.group({
        email: ['', [Validators.required, Validators.email]],
    });

    protected async onSubmit(): Promise<void> {
        if (this.form.invalid) {
            return;
        }
        this.saving.set(true);
        this.error.set(null);
        try {
            const {email} = this.form.getRawValue();
            await this.auth.requestPasswordReset(email);
            this.sentTo.set(email.trim());
        } catch (e: unknown) {
            this.error.set(e instanceof HttpErrorResponse ? errorMessage(e) : 'Could not send the link.');
        } finally {
            this.saving.set(false);
        }
    }
}
