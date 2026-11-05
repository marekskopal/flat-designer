import {Routes} from '@angular/router';
import {adminGuard, authGuard, guestGuard} from '@app/core/guards/auth.guards';
import {LayoutComponent} from '@app/shared/layout/layout.component';

export const appRoutes: Routes = [
    {
        path: 'login',
        canActivate: [guestGuard],
        title: 'titles.signIn',
        loadComponent: async () => (await import('@app/authentication/login.component')).LoginComponent,
    },
    {
        path: 'sign-up',
        canActivate: [guestGuard],
        title: 'titles.signUp',
        loadComponent: async () => (await import('@app/authentication/sign-up.component')).SignUpComponent,
    },
    {
        path: 'forgot-password',
        canActivate: [guestGuard],
        title: 'titles.forgotPassword',
        loadComponent: async () => (await import('@app/authentication/forgot-password.component')).ForgotPasswordComponent,
    },
    {
        // The link of a verification mail; it works signed in or out, so no guard.
        path: 'verify-email',
        title: 'titles.verifyEmail',
        loadComponent: async () => (await import('@app/authentication/verify-email.component')).VerifyEmailComponent,
    },
    {
        // The link of a password reset mail; it may be opened while signed in (it signs in anew), so no guard.
        path: 'reset-password',
        title: 'titles.resetPassword',
        loadComponent: async () => (await import('@app/authentication/reset-password.component')).ResetPasswordComponent,
    },
    {
        // OAuth consent for MCP clients; signs in inline, so no guard.
        path: 'oauth/authorize',
        title: 'titles.connect',
        loadComponent: async () => (await import('@app/oauth/oauth-authorize.component')).OAuthAuthorizeComponent,
    },
    {
        // A share link: a guest view of one floor, also for signed-in users (no guard).
        path: 's/:token',
        title: 'titles.sharedView',
        loadComponent: async () => (await import('@app/shared-viewer/shared-viewer-page.component')).SharedViewerPageComponent,
    },
    {
        path: '',
        component: LayoutComponent,
        canActivate: [authGuard],
        children: [
            {path: '', pathMatch: 'full', redirectTo: 'projects'},
            {
                path: 'projects',
                title: 'titles.projects',
                loadComponent: async () => (await import('@app/projects/projects.component')).ProjectsComponent,
            },
            {
                path: 'projects/:projectId',
                title: 'titles.project',
                loadComponent: async () => (await import('@app/projects/project-detail.component')).ProjectDetailComponent,
            },
            {
                path: 'projects/:projectId/cut-list',
                title: 'titles.cutList',
                loadComponent: async () => (await import('@app/projects/cut-list/cut-list.component')).CutListComponent,
            },
            {
                path: 'projects/:projectId/quantities',
                title: 'titles.quantities',
                loadComponent: async () => (await import('@app/projects/quantities/quantities.component')).QuantitiesComponent,
            },
            {
                path: 'projects/:projectId/floors/:floorId',
                loadComponent: async () => (await import('@app/viewer-page/viewer-page.component')).ViewerPageComponent,
            },
            {
                path: 'furniture',
                title: 'titles.furniture',
                loadComponent: async () => (await import('@app/furniture/furniture-library.component')).FurnitureLibraryComponent,
            },
            {
                path: 'furniture/:definitionId',
                title: 'titles.furniture',
                loadComponent: async () => (await import('@app/furniture/furniture-editor.component')).FurnitureEditorComponent,
            },
            {
                path: 'account',
                title: 'titles.account',
                loadComponent: async () => (await import('@app/account/account.component')).AccountComponent,
            },
            {
                path: 'admin/users',
                canActivate: [adminGuard],
                title: 'titles.users',
                loadComponent: async () => (await import('@app/admin/admin-users.component')).AdminUsersComponent,
            },
        ],
    },
    {path: '**', redirectTo: ''},
];
