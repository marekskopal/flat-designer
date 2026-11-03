import {Routes} from '@angular/router';
import {adminGuard, authGuard, guestGuard} from '@app/core/guards/auth.guards';
import {LayoutComponent} from '@app/shared/layout/layout.component';

export const appRoutes: Routes = [
    {
        path: 'login',
        canActivate: [guestGuard],
        title: 'Sign in · Izbik',
        loadComponent: async () => (await import('@app/authentication/login.component')).LoginComponent,
    },
    {
        path: 'sign-up',
        canActivate: [guestGuard],
        title: 'Sign up · Izbik',
        loadComponent: async () => (await import('@app/authentication/sign-up.component')).SignUpComponent,
    },
    {
        path: 'forgot-password',
        canActivate: [guestGuard],
        title: 'Reset password · Izbik',
        loadComponent: async () => (await import('@app/authentication/forgot-password.component')).ForgotPasswordComponent,
    },
    {
        // The link of a verification mail; it works signed in or out, so no guard.
        path: 'verify-email',
        title: 'Confirm email · Izbik',
        loadComponent: async () => (await import('@app/authentication/verify-email.component')).VerifyEmailComponent,
    },
    {
        // The link of a password reset mail; it may be opened while signed in (it signs in anew), so no guard.
        path: 'reset-password',
        title: 'Choose a new password · Izbik',
        loadComponent: async () => (await import('@app/authentication/reset-password.component')).ResetPasswordComponent,
    },
    {
        // OAuth consent for MCP clients; signs in inline, so no guard.
        path: 'oauth/authorize',
        title: 'Connect · Izbik',
        loadComponent: async () => (await import('@app/oauth/oauth-authorize.component')).OAuthAuthorizeComponent,
    },
    {
        // A share link: a guest view of one floor, also for signed-in users (no guard).
        path: 's/:token',
        title: 'Shared view · Izbik',
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
                title: 'Projects · Izbik',
                loadComponent: async () => (await import('@app/projects/projects.component')).ProjectsComponent,
            },
            {
                path: 'projects/:projectId',
                title: 'Project · Izbik',
                loadComponent: async () => (await import('@app/projects/project-detail.component')).ProjectDetailComponent,
            },
            {
                path: 'projects/:projectId/cut-list',
                title: 'Cut list · Izbik',
                loadComponent: async () => (await import('@app/projects/cut-list/cut-list.component')).CutListComponent,
            },
            {
                path: 'projects/:projectId/quantities',
                title: 'Quantities & cost · Izbik',
                loadComponent: async () => (await import('@app/projects/quantities/quantities.component')).QuantitiesComponent,
            },
            {
                path: 'projects/:projectId/floors/:floorId',
                title: 'Izbik',
                loadComponent: async () => (await import('@app/viewer-page/viewer-page.component')).ViewerPageComponent,
            },
            {
                path: 'furniture',
                title: 'Furniture · Izbik',
                loadComponent: async () => (await import('@app/furniture/furniture-library.component')).FurnitureLibraryComponent,
            },
            {
                path: 'furniture/:definitionId',
                title: 'Furniture · Izbik',
                loadComponent: async () => (await import('@app/furniture/furniture-editor.component')).FurnitureEditorComponent,
            },
            {
                path: 'account',
                title: 'Account · Izbik',
                loadComponent: async () => (await import('@app/account/account.component')).AccountComponent,
            },
            {
                path: 'admin/users',
                canActivate: [adminGuard],
                title: 'Users · Izbik',
                loadComponent: async () => (await import('@app/admin/admin-users.component')).AdminUsersComponent,
            },
        ],
    },
    {path: '**', redirectTo: ''},
];
