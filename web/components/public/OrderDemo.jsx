"use client";

import { useEffect, useRef, useState } from "react";

// Minutes after 12:40 for each message of the script, so the clock looks like a real order.
const MINUTES = [0, 0, 1, 1, 2, 2, 2, 3, 4, 4, 4, 4, 22, 23, 23, 23, 34, 34, 34, 64, 65, 65];
const OUT_DELAY = 2400;
const TYPING_DELAY = 1600;
const RESTART_DELAY = 9000;
// Extra pause when the next message is on another phone, so the eye can follow.
const SWITCH_DELAY = 1500;

function clock(i) {
  const total = 12 * 60 + 40 + (MINUTES[i] ?? MINUTES[MINUTES.length - 1]);
  return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, "0")}`;
}

/** WhatsApp chats playing one order. `chats` picks which phones to show: c, s and/or d. */
export default function OrderDemo({ demo, chats = ["c", "s", "d"], showSteps = false }) {
  const { script } = demo;
  const [count, setCount] = useState(0);
  const [typing, setTyping] = useState(null);
  const rootRef = useRef(null);
  const stripRef = useRef(null);
  const bodyRefs = useRef({});

  useEffect(() => {
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
      setCount(script.length);
      return;
    }
    let timer;
    let started = false;
    const play = (i) => {
      if (i >= script.length) {
        timer = setTimeout(() => { setCount(0); play(0); }, RESTART_DELAY);
        return;
      }
      const msg = script[i];
      const wait = OUT_DELAY + (i > 0 && script[i - 1].chat !== msg.chat ? SWITCH_DELAY : 0);
      if (msg.out) {
        timer = setTimeout(() => { setCount(i + 1); play(i + 1); }, wait);
      } else {
        timer = setTimeout(() => {
          setTyping(msg.chat);
          timer = setTimeout(() => { setTyping(null); setCount(i + 1); play(i + 1); }, TYPING_DELAY);
        }, wait - TYPING_DELAY / 2);
      }
    };
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting && !started) {
        started = true;
        play(0);
      }
    }, { threshold: 0.3 });
    if (rootRef.current) observer.observe(rootRef.current);
    return () => { observer.disconnect(); clearTimeout(timer); };
  }, [script]);

  const last = count > 0 ? script[count - 1] : null;
  const activeChat = typing || last?.chat || chats[0];

  useEffect(() => {
    Object.values(bodyRefs.current).forEach((el) => {
      if (el) el.scrollTo({ top: el.scrollHeight, behavior: count === 0 ? "auto" : "smooth" });
    });
    // On narrow screens the phones scroll sideways: bring the one with the new message into view.
    const strip = stripRef.current;
    const phone = strip?.querySelector(`[data-chat="${activeChat}"]`);
    if (strip && phone && strip.scrollWidth > strip.clientWidth + 4) {
      strip.scrollTo({ left: phone.offsetLeft - (strip.clientWidth - phone.clientWidth) / 2, behavior: "smooth" });
    }
  }, [count, typing, activeChat]);

  return (
    <div className={`wa-demo${chats.length > 1 ? " wa-demo-multi" : ""}`} ref={rootRef} aria-hidden="true">
      {showSteps && (
        <ol className="wa-steps">
          {demo.steps.map((label, i) => (
            <li key={label} className={last && last.step >= i ? "done" : ""}>
              <span>{i + 1}</span>{label}
            </li>
          ))}
        </ol>
      )}
      <div className="wa-strip" ref={stripRef}>
        {chats.map((chat) => {
          const messages = script.map((m, i) => ({ ...m, i })).filter((m, i) => m.chat === chat && i < count);
          return (
            <div key={chat} className={`wa-col${chats.length > 1 && activeChat === chat ? " is-active" : ""}`} data-chat={chat}>
              {chats.length > 1 && <p className="wa-who">{demo.who[chat]}</p>}
              <div className="wa-phone">
                <div className="wa-head">
                  <svg className="wa-back" viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 4l1.4 1.4L7.8 11H20v2H7.8l5.6 5.6L12 20l-8-8z" /></svg>
                  <span className="wa-avatar">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src="/Logo.png" alt="" width={26} height={26} />
                  </span>
                  <div className="wa-title">
                    <strong>{demo.shop}</strong>
                    <span>{typing === chat ? demo.typing : demo.online}</span>
                  </div>
                  <svg viewBox="0 0 24 24" width="21" height="21"><path fill="currentColor" d="M17 10.5V7a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h12a1 1 0 001-1v-3.5l4 4v-11z" /></svg>
                  <svg viewBox="0 0 24 24" width="19" height="19"><path fill="currentColor" d="M6.6 10.8a15.1 15.1 0 006.6 6.6l2.2-2.2a1 1 0 011-.25 11.4 11.4 0 003.6.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1c0 1.25.2 2.45.57 3.57a1 1 0 01-.25 1z" /></svg>
                  <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 7a2 2 0 110-4 2 2 0 010 4zm0 7a2 2 0 110-4 2 2 0 010 4zm0 7a2 2 0 110-4 2 2 0 010 4z" /></svg>
                </div>
                <div className="wa-body" ref={(el) => { bodyRefs.current[chat] = el; }}>
                  <span className="wa-date">{demo.today}</span>
                  {messages.map((m, k) => {
                    const first = k === 0 || !!messages[k - 1].out !== !!m.out;
                    return (
                      <div key={m.i} className={`wa-msg ${m.out ? "out" : "in"}${first ? " tail" : ""}`}>
                        <div className="wa-bubble">
                          <span className="wa-text">{m.text}</span>
                          <span className="wa-meta">
                            {clock(m.i)}
                            {m.out && <svg viewBox="0 0 16 11" width="16" height="11"><path fill="currentColor" d="M11.1.7L5.2 6.6 3.2 4.6 2 5.8l3.2 3.2 7.1-7.1zM14.5.7L8.6 6.6l-.6-.6-1.2 1.2L8.6 9l7.1-7.1z" /></svg>}
                          </span>
                        </div>
                        {m.buttons?.map((b) => <span key={b} className="wa-button">{b}</span>)}
                      </div>
                    );
                  })}
                  {typing === chat && <div className="wa-msg in tail"><div className="wa-bubble wa-typing"><i /><i /><i /></div></div>}
                </div>
                <div className="wa-input">
                  <span className="wa-field">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 2a10 10 0 100 20 10 10 0 000-20zm-3.5 6a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm7 0a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM12 18a6 6 0 01-5.3-3.2h10.6A6 6 0 0112 18z" /></svg>
                    <em>{demo.message}</em>
                    <svg viewBox="0 0 24 24" width="19" height="19"><path fill="currentColor" d="M16.5 6.5v10.6a4.5 4.5 0 01-9 0V5.6a3 3 0 016 0v10a1.5 1.5 0 01-3 0V6.5H9v9.1a3 3 0 006 0v-10a4.5 4.5 0 00-9 0v11.5a6 6 0 0012 0V6.5z" /></svg>
                    <svg viewBox="0 0 24 24" width="19" height="19"><path fill="currentColor" d="M12 9a3.5 3.5 0 100 7 3.5 3.5 0 000-7zm7-3h-3.2L14 4h-4L8.2 6H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2z" /></svg>
                  </span>
                  <span className="wa-mic">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 15a3 3 0 003-3V6a3 3 0 00-6 0v6a3 3 0 003 3zm5.3-3a5.3 5.3 0 01-10.6 0H5a7 7 0 006 6.9V21h2v-2.1A7 7 0 0019 12z" /></svg>
                  </span>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
