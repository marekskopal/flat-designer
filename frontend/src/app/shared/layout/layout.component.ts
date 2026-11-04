import {NgOptimizedImage} from '@angular/common';
import {ChangeDetectionStrategy, Component, computed, inject, OnInit, signal} from '@angular/core';
import {RouterLink, RouterLinkActive, RouterOutlet} from '@angular/router';
import {AlertService} from '@app/services/alert.service';
import {AuthenticationService} from '@app/services/authentication.service';
import {CurrentUserService} from '@app/services/current-user.service';

/** Where "Report a problem" leads: a new issue in the project's GitHub repository (its templates ask what is needed). */
const ReportProblemUrl = 'https://github.com/marekskopal/izbik/issues/new/choose';

/**
 * Shell of the signed-in app: top bar with navigation and the user's menu, content below; while the user's email is not
 * confirmed (and the server sends mail), a banner offers to send the link again.
 * On a phone the navigation and the user's menu fold into a hamburger menu.
 */
@Component({
    selector: 'fd-layout',
    imports: [NgOptimizedImage, RouterOutlet, RouterLink, RouterLinkActive],
    templateUrl: './layout.component.html',
    styleUrl: './layout.component.scss',
    changeDetection: ChangeDetectionStrategy.OnPush,
    host: {'(document:keydown.escape)': 'menuOpen.set(false)'},
})
export class LayoutComponent implements OnInit {
    protected readonly currentUser = inject(CurrentUserService);
    protected readonly reportUrl = ReportProblemUrl;
    protected readonly menuOpen = signal(false);
    protected readonly resending = signal(false);
    private readonly auth = inject(AuthenticationService);
    private readonly alerts = inject(AlertService);
    private readonly mail = signal(false);
    /** Hidden for the rest of the session by "Later". */
    private readonly bannerDismissed = signal(false);
    protected readonly verifyBanner = computed(() => {
        const user = this.currentUser.currentUser();
        return user !== null && !user.emailVerified && this.mail() && !this.bannerDismissed();
    });

    public ngOnInit(): void {
        // Failure is already reported by the interceptors (toast, or sign-out on 401).
        this.currentUser.get().catch(() => undefined);
        void this.auth.options().then((options) => this.mail.set(options.mail));
    }

    protected async resendVerification(): Promise<void> {
        this.resending.set(true);
        try {
            await this.auth.resendVerification();
            this.alerts.success(`A new link is on its way to ${this.currentUser.currentUser()?.email ?? 'your email'}.`);
        } catch {
            // The error interceptor shows the toast.
        } finally {
            this.resending.set(false);
        }
    }

    protected dismissBanner(): void {
        this.bannerDismissed.set(true);
    }

    protected toggleMenu(): void {
        this.menuOpen.update((open) => !open);
    }

    protected logout(): void {
        this.auth.logout();
    }
}
