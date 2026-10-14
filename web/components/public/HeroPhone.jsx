"use client";

import { useEffect, useState } from "react";
import { Svg, icons } from "@/components/public/OrderDemo";

const STEP_DELAY = 1700;
const TYPING_DELAY = 1100;
const RESTART_DELAY = 5000;

/** One phone next to the headline: a short WhatsApp order that plays on a loop. */
export default function HeroPhone({ chat }) {
  const { messages } = chat;
  const [count, setCount] = useState(0);
  const [typing, setTyping] = useState(false);

  useEffect(() => {
    if (window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
      setCount(messages.length);
      return;
    }
    if (count >= messages.length) {
      const id = setTimeout(() => setCount(0), RESTART_DELAY);
      return () => clearTimeout(id);
    }
    const next = messages[count];
    if (!next.out && !typing) {
      const id = setTimeout(() => setTyping(true), count ? STEP_DELAY - TYPING_DELAY : 600);
      return () => clearTimeout(id);
    }
    const id = setTimeout(() => { setTyping(false); setCount(count + 1); }, next.out ? (count ? STEP_DELAY : 900) : TYPING_DELAY);
    return () => clearTimeout(id);
  }, [count, typing, messages]);

  const shown = messages.slice(0, count);

  return (
    <div className="hero-phone wa-demo" aria-hidden="true">
      <div className="wa-phone">
        <div className="wa-head">
          <Svg d={icons.back} size={20} className="wa-back" />
          <span className="wa-avatar">
            {/* eslint-disable-next-line @next/next/no-img-element */}
            <img src="/Logo.png" alt="" width={24} height={24} />
          </span>
          <div className="wa-title">
            <strong>{chat.shop}</strong>
            <span>{chat.online}</span>
          </div>
          <Svg d={icons.call} size={17} />
          <Svg d={icons.more} size={18} />
        </div>
        <div className="wa-body">
          {shown.map((m, i) => {
            const first = i === 0 || shown[i - 1].out !== m.out;
            const tapped = messages[i + 1]?.out && m.buttons?.includes(messages[i + 1].text);
            return (
              <div key={`${i}-${m.text}`} className="wa-row">
                <div className={`wa-msg ${m.out ? "out" : "in"}${first ? " tail" : ""}`}>
                  <div className="wa-bubble">
                    {!m.out && first && <span className="wa-bot">🤖 {chat.bot}</span>}
                    <span className="wa-text">{m.text}</span>
                    <span className="wa-meta">
                      {m.time}
                      {m.out && <Svg d={icons.ticks} size={16} box="0 0 16 11" />}
                    </span>
                  </div>
                  {m.buttons?.map((b) => (
                    <span key={b} className={`wa-button${tapped && count === i + 1 ? " hero-tap" : ""}`}>{b}</span>
                  ))}
                </div>
              </div>
            );
          })}
          {typing && <div className="wa-row"><div className="wa-msg in tail"><div className="wa-bubble wa-typing"><i /><i /><i /></div></div></div>}
        </div>
        <div className="wa-input">
          <span className="wa-field">
            <Svg d={icons.emoji} />
            <em>{chat.message}</em>
            <Svg d={icons.clip} size={19} />
            <Svg d={icons.camera} size={19} />
          </span>
          <span className="wa-mic"><Svg d={icons.mic} /></span>
        </div>
      </div>
    </div>
  );
}
