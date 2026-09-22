import { Link } from '@tanstack/react-router';

export function NotFound() {
    return (
        <div className="t-page">
            <div className="t-state">
                <div>
                    <strong>Page not found</strong>
                    <p>This dashboard has no such page. <Link to="/">Go to the overview</Link>.</p>
                </div>
            </div>
        </div>
    );
}
