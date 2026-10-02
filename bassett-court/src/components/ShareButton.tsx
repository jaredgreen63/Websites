'use client';

import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Share a listing.
 *
 * On a phone this opens the native share sheet, which is the case that
 * matters: a salesperson standing on the lot texting a customer the vehicle
 * they just talked about. Where that API does not exist — most desktop
 * browsers — it falls back to an explicit menu rather than silently copying,
 * so the button never does something the label did not promise.
 */
export function ShareButton({
  title,
  text,
  className = '',
}: {
  title: string;
  text?: string;
  className?: string;
}) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const [canNativeShare, setCanNativeShare] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Resolved on the client: the absolute URL is only known in the browser, and
  // navigator.share does not exist during the static build.
  useEffect(() => {
    setCanNativeShare(typeof navigator !== 'undefined' && typeof navigator.share === 'function');
  }, []);

  useEffect(() => {
    if (!open) return;

    const onPointerDown = (event: PointerEvent) => {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    };
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };

    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('keydown', onKeyDown);
    };
  }, [open]);

  const shareUrl = useCallback(() => (typeof window === 'undefined' ? '' : window.location.href), []);

  async function copyLink() {
    const url = shareUrl();
    let ok = false;

    try {
      // Only available over https or on localhost.
      await navigator.clipboard.writeText(url);
      ok = true;
    } catch {
      // Older browsers, and any page served over plain http.
      try {
        const field = document.createElement('textarea');
        field.value = url;
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        ok = document.execCommand('copy');
        document.body.removeChild(field);
      } catch {
        ok = false;
      }
    }

    if (ok) {
      setCopied(true);
      setOpen(false);
      window.setTimeout(() => setCopied(false), 2200);
    } else {
      // Rather than claim success, show the link so it can be copied by hand.
      window.prompt('Copy this link:', url);
    }
  }

  async function onShareClick() {
    if (canNativeShare) {
      try {
        await navigator.share({ title, text, url: shareUrl() });
        return;
      } catch (error) {
        // A dismissed share sheet is not a failure, so do not fall through to
        // the menu and make it look like something went wrong.
        if ((error as DOMException)?.name === 'AbortError') return;
      }
    }
    setOpen((value) => !value);
  }

  return (
    <div ref={containerRef} className={`relative ${className}`}>
      <button
        type="button"
        onClick={onShareClick}
        aria-expanded={canNativeShare ? undefined : open}
        aria-haspopup={canNativeShare ? undefined : 'menu'}
        className="btn btn-outline gap-1.5 px-3 py-1.5 text-[0.8125rem]"
      >
        {copied ? <CheckIcon /> : <ShareIcon />}
        {copied ? 'Link copied' : 'Share'}
      </button>

      {open && !canNativeShare ? (
        <div
          role="menu"
          className="absolute right-0 z-30 mt-2 w-52 overflow-hidden rounded-[var(--radius-card)] py-1"
          style={{
            backgroundColor: 'var(--surface-raised)',
            border: '1px solid var(--border-subtle)',
            boxShadow: 'var(--shadow-lift)',
          }}
        >
          <MenuButton onClick={copyLink}>Copy link</MenuButton>
          <MenuLink href={`sms:?&body=${encodeURIComponent(`${title} ${shareUrl()}`)}`}>
            Text message
          </MenuLink>
          <MenuLink
            href={`mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent(`${text ? `${text}\n\n` : ''}${shareUrl()}`)}`}
          >
            Email
          </MenuLink>
          <MenuLink
            href={`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareUrl())}`}
            external
          >
            Facebook
          </MenuLink>
        </div>
      ) : null}
    </div>
  );
}

function MenuButton({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
  return (
    <button
      type="button"
      role="menuitem"
      onClick={onClick}
      className="block w-full px-3.5 py-2 text-left text-[0.8125rem] transition-colors hover:bg-[var(--surface-sunken)]"
    >
      {children}
    </button>
  );
}

function MenuLink({
  href, children, external = false,
}: {
  href: string;
  children: React.ReactNode;
  external?: boolean;
}) {
  return (
    <a
      href={href}
      role="menuitem"
      {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
      className="block px-3.5 py-2 text-[0.8125rem] transition-colors hover:bg-[var(--surface-sunken)]"
    >
      {children}
    </a>
  );
}

function ShareIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="18" cy="5" r="3" />
      <circle cx="6" cy="12" r="3" />
      <circle cx="18" cy="19" r="3" />
      <path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={{ color: 'var(--accent)' }}>
      <path d="M20 6L9 17l-5-5" />
    </svg>
  );
}
