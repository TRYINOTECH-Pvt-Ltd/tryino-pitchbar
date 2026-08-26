import { Head, Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';

import { dashboard, login, register } from '@/routes';

import './prism.css';

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
    title?: string;
    description?: string;
    canRegister?: boolean;
    hasUser?: boolean;
    children: ReactNode;
};

export default function PrismLayout({
    brand,
    navItems,
    footer,
    title,
    description,
    canRegister = false,
    hasUser = false,
    children,
}: Props) {
    useEffect(() => {
        document.body.classList.add('prism-body');

        return () => document.body.classList.remove('prism-body');
    }, []);

    const primaryHref = hasUser
        ? dashboard().url
        : canRegister
          ? register().url
          : login().url;

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
                    href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap"
                />
            </Head>

            <div className="prism-root">
                <Nav
                    brand={brand}
                    navItems={navItems}
                    primaryHref={primaryHref}
                    hasUser={hasUser}
                />
                {children}
                <Footer brand={brand} footer={footer} />
            </div>
        </>
    );
}

function Nav({
    brand,
    navItems,
    primaryHref,
    hasUser,
}: {
    brand: string;
    navItems: ContentLink[];
    primaryHref: string;
    hasUser: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <header className="nav-shell">
            <div className="wrap nav">
                <Link href="/" className="logo" aria-label={brand}>
                    <span className="logo-mark" />
                    <span>{brand}</span>
                </Link>
                <nav className="nav-links" aria-label="Primary">
                    {navItems.map((item) => (
                        <Link
                            key={`${item.label}-${item.href}`}
                            href={item.href}
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
                <div className="nav-right">
                    <Link className="signin" href={login().url}>
                        Sign in
                    </Link>
                    <Link className="btn btn-sm btn-primary" href={primaryHref}>
                        {hasUser ? 'Dashboard' : 'Get started'}{' '}
                        <span aria-hidden="true">↗</span>
                    </Link>
                    <button
                        type="button"
                        className="menu-btn"
                        aria-label="Menu"
                        aria-expanded={open}
                        onClick={() => setOpen((v) => !v)}
                    >
                        <svg
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2"
                        >
                            <path d="M4 7h16M4 12h16M4 17h16" />
                        </svg>
                    </button>
                </div>
            </div>
            <div className={`mobile-sheet ${open ? 'open' : ''}`}>
                {navItems.map((item) => (
                    <Link
                        key={`${item.label}-${item.href}`}
                        href={item.href}
                        onClick={() => setOpen(false)}
                    >
                        {item.label}
                    </Link>
                ))}
                <div className="mobile-actions">
                    <Link
                        className="btn btn-sm btn-light"
                        href={login().url}
                        onClick={() => setOpen(false)}
                    >
                        Sign in
                    </Link>
                    <Link
                        className="btn btn-sm btn-primary"
                        href={primaryHref}
                        onClick={() => setOpen(false)}
                    >
                        {hasUser ? 'Dashboard' : 'Get started'}{' '}
                        <span aria-hidden="true">↗</span>
                    </Link>
                </div>
            </div>
        </header>
    );
}

function Footer({ brand, footer }: { brand: string; footer: FooterContent }) {
    const groups = footer.groups.slice(0, 3);
    const legal = { title: footer.legal_title, links: footer.legal_links };

    return (
        <footer className="footer">
            <div className="wrap">
                <div className="foot-grid">
                    <div className="foot-col">
                        <Link href="/" className="logo">
                            <span className="logo-mark" />
                            <span>{brand}</span>
                        </Link>
                        <p className="foot-tag">{footer.brand_description}</p>
                        <div className="foot-social">
                            {footer.socials.map((social) => (
                                <a
                                    key={`${social.label}-${social.href}`}
                                    href={social.href}
                                    aria-label={social.label}
                                >
                                    <span
                                        style={{
                                            fontSize: 11,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {social.label.slice(0, 2).toUpperCase()}
                                    </span>
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
                <div className="foot-bottom">
                    <span>{footer.copyright}</span>
                    <span>Made for high-intent visitors.</span>
                </div>
            </div>
        </footer>
    );
}
