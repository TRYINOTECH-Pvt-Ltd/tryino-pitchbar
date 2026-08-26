import { Head, Link } from '@inertiajs/react';
import { useEffect } from 'react';
import type { ReactNode } from 'react';

import { login } from '@/routes';

import './aurora.css';

type ContentLink = { label: string; href: string };

type FooterContent = {
    brand_description: string;
    socials: ContentLink[];
    groups: Array<{ title: string; links: ContentLink[] }>;
    legal_title: string;
    legal_links: ContentLink[];
    copyright: string;
};

type Props = {
    brand: string;
    navItems: ContentLink[];
    footer: FooterContent;
    ticker?: string[];
    title?: string;
    description?: string;
    children: ReactNode;
};

export default function AuroraLayout({
    brand,
    navItems,
    footer,
    ticker,
    title,
    description,
    children,
}: Props) {
    useEffect(() => {
        document.body.classList.add('aurora-body');

        return () => document.body.classList.remove('aurora-body');
    }, []);

    const tickerItems =
        ticker && ticker.length > 0 ? ticker : defaultTicker(brand);
    const repeated = [...tickerItems, ...tickerItems];

    return (
        <>
            <Head title={title ?? brand}>
                {description ? (
                    <meta name="description" content={description} />
                ) : null}
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link
                    rel="preconnect"
                    href="https://fonts.gstatic.com"
                    crossOrigin=""
                />
                <link
                    rel="stylesheet"
                    href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=JetBrains+Mono:wght@400;500&display=swap"
                />
            </Head>

            <div className="aurora-root font-grotesk density-regular theme-light">
                <Nav brand={brand} navItems={navItems} />
                <div className="ticker">
                    <div className="ticker-track">
                        {repeated.map((label, idx) => (
                            <span key={`${label}-${idx}`}>
                                <span className="pip" />
                                {label}
                            </span>
                        ))}
                    </div>
                </div>
                {children}
                <Footer brand={brand} footer={footer} />
            </div>
        </>
    );
}

function defaultTicker(brand: string): string[] {
    return [
        `LIVE DEMO — ASK ${brand.toUpperCase()} ANYTHING`,
        'NO CREDIT CARD REQUIRED',
        'INSTALL IN 5 MINUTES',
        'SOC 2 · GDPR · HIPAA READY',
    ];
}

function Nav({ brand, navItems }: { brand: string; navItems: ContentLink[] }) {
    return (
        <nav className="nav">
            <div className="nav-brand">
                <span className="brand-mark" />
                <span>{brand.toUpperCase()}</span>
            </div>
            <div className="nav-links">
                {navItems.map((item) => (
                    <Link key={`${item.label}-${item.href}`} href={item.href}>
                        {item.label}
                    </Link>
                ))}
            </div>
            <div className="nav-end">
                <span className="status">
                    <span className="pulse" />
                    ALL SYSTEMS LIVE
                </span>
                <Link className="cta" href={login().url}>
                    Login&nbsp;<span className="arrow">→</span>
                </Link>
            </div>
        </nav>
    );
}

function Footer({ brand, footer }: { brand: string; footer: FooterContent }) {
    const groups = footer.groups.slice(0, 3);
    const legal = { title: footer.legal_title, links: footer.legal_links };

    return (
        <>
            <div className="foot-mark-wrap">
                <svg
                    viewBox="0 0 1000 200"
                    preserveAspectRatio="xMidYMid meet"
                    aria-hidden="true"
                >
                    <text x="500" y="170" textAnchor="middle" fontSize="220">
                        {brand.toUpperCase()}
                    </text>
                </svg>
            </div>
            <footer className="footer">
                <div className="wrap">
                    <div className="foot-grid">
                        <div className="foot-brand">
                            <span className="brand-mark" />
                            <p>{footer.brand_description}</p>
                            <div className="social">
                                {footer.socials.map((social) => (
                                    <a
                                        key={`${social.label}-${social.href}`}
                                        href={social.href}
                                        aria-label={social.label}
                                    >
                                        {social.label}
                                    </a>
                                ))}
                            </div>
                        </div>
                        {[...groups, legal].map((group) => (
                            <div key={group.title} className="foot-col">
                                <h5>{group.title}</h5>
                                <ul>
                                    {group.links.map((link) => (
                                        <li key={`${link.label}-${link.href}`}>
                                            <a href={link.href}>{link.label}</a>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                    <div className="foot-bar">
                        <span>{footer.copyright.toUpperCase()}</span>
                    </div>
                </div>
            </footer>
        </>
    );
}
