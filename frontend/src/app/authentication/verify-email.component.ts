import {NgOptimizedImage} from '@angular/common';
import {HttpErrorResponse} from '@angular/common/http';
import {ChangeDetectionStrategy, Component, computed, inject, OnInit, signal} from '@angular/core';
import {ActivatedRoute, RouterLink} from '@angular/router';
import {errorMessage} from '@app/core/interceptors/error.interceptor';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

type VerifyState = {kind: 'verifying'} | {kind: 'verified'} | {kind: 'failed'; message: string};

/** The page a verification mail links to (`/verify-email?token=…`); confirms the address as it opens. */
@Component({
    selector: 'fd-verify-email',
    imports: [NgOptimizedImage, RouterLink],
    templateUrl: './verify-email.component.html',
    styleUrl: './auth-page.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
})
export class VerifyEmailComponent implements OnInit {
    private readonly auth = inject(AuthenticationService);
    private readonly currentUser = inject(CurrentUserService);
    private readonly token = inject(ActivatedRoute).snapshot.queryParamMap.get('token');

    protected readonly signedIn = this.auth.isLoggedIn;
    protected readonly state = signal<VerifyState>({kind: 'verifying'});
    protected readonly failure = computed(() => {
        const state = this.state();
        return state.kind === 'failed' ? state.message : null;
    });

    public ngOnInit(): void {
        void this.verify();
    }

    private async verify(): Promise<void> {
        if (this.token === null) {
            this.state.set({kind: 'failed', message: 'This verification link is incomplete. Open the link from the mail again.'});
            return;
        }
        try {
            await this.auth.verifyEmail(this.token);
            this.state.set({kind: 'verified'});
            if (this.auth.isLoggedIn()) {
                // The banner reads the cached profile; refresh it. A failure is reported by the interceptors.
                await this.currentUser.load().catch(() => undefined);
            }
        } catch (e: unknown) {
            this.state.set({kind: 'failed', message: e instanceof HttpErrorResponse ? errorMessage(e) : 'The email could not be confirmed.'});
        }
    }
}
