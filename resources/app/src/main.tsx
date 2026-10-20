import '@fontsource/jetbrains-mono/400.css';
import '@fontsource/jetbrains-mono/500.css';
import '@fontsource/jetbrains-mono/600.css';
import '@fontsource/plus-jakarta-sans/600.css';
import '@fontsource/plus-jakarta-sans/700.css';
import './styles/tokens.css';
import './styles/app.css';
import './styles/shell.css';

import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider } from '@tanstack/react-router';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { shouldRetry } from './api/client';
import { boot } from './boot';
import { makeRouter } from './router';

const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 15_000,
            refetchOnWindowFocus: false,
            // Forbidden / not-found / invalid are answers and a timeout would
            // only time out again; a dropped connection is worth two more tries.
            retry: (failures, error) => shouldRetry(failures, error, 2),
        },
    },
});

const router = makeRouter(boot().base);

const el = document.getElementById('app');

if (el) {
    createRoot(el).render(
        <StrictMode>
            <QueryClientProvider client={queryClient}>
                <RouterProvider router={router} />
            </QueryClientProvider>
        </StrictMode>,
    );
}
