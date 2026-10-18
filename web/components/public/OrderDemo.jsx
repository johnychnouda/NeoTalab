"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";

const OUT_DELAY = 2600;
const TYPING_DELAY = 1600;
// Extra pauses when the next message is on another phone, or starts a new step.
const SWITCH_DELAY = 1400;
const STEP_DELAY = 2000;
// The visitor plays the customer: their next tap glows, and the demo taps it for them after this.
const AUTO_DELAY = 4000;
// Time to read the message just shown: longer messages (an order summary) stay longer.
const READ_MS_PER_CHAR = 45;
const READ_MAX = 7000;
const readTime = (msg) => Math.min(READ_MAX, (msg?.text?.length || 0) * READ_MS_PER_CHAR);

const icons = {
  back: "M12 4l1.4 1.4L7.8 11H20v2H7.8l5.6 5.6L12 20l-8-8z",
  video: "M17 10.5V7a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h12a1 1 0 001-1v-3.5l4 4v-11z",
  call: "M6.6 10.8a15.1 15.1 0 006.6 6.6l2.2-2.2a1 1 0 011-.25 11.4 11.4 0 003.6.57 1 1 0 011 1V20a1 1 0 01-1 1A17 17 0 013 4a1 1 0 011-1h3.5a1 1 0 011 1c0 1.25.2 2.45.57 3.57a1 1 0 01-.25 1z",
  more: "M12 7a2 2 0 110-4 2 2 0 010 4zm0 7a2 2 0 110-4 2 2 0 010 4zm0 7a2 2 0 110-4 2 2 0 010 4z",
  emoji: "M12 2a10 10 0 100 20 10 10 0 000-20zm-3.5 6a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm7 0a1.5 1.5 0 110 3 1.5 1.5 0 010-3zM12 18a6 6 0 01-5.3-3.2h10.6A6 6 0 0112 18z",
  clip: "M16.5 6.5v10.6a4.5 4.5 0 01-9 0V5.6a3 3 0 016 0v10a1.5 1.5 0 01-3 0V6.5H9v9.1a3 3 0 006 0v-10a4.5 4.5 0 00-9 0v11.5a6 6 0 0012 0V6.5z",
  camera: "M12 9a3.5 3.5 0 100 7 3.5 3.5 0 000-7zm7-3h-3.2L14 4h-4L8.2 6H5a2 2 0 00-2 2v10a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2z",
  mic: "M12 15a3 3 0 003-3V6a3 3 0 00-6 0v6a3 3 0 003 3zm5.3-3a5.3 5.3 0 01-10.6 0H5a7 7 0 006 6.9V21h2v-2.1A7 7 0 0019 12z",
  play: "M8 5v14l11-7z",
  pause: "M6 5h4v14H6zm8 0h4v14h-4z",
  replay: "M12 5V1L7 6l5 5V7a6 6 0 11-6 6H4a8 8 0 108-8z",
  chevron: "M9.3 6.7L10.7 5.3 17.4 12l-6.7 6.7-1.4-1.4L14.6 12z",
  send: "M3 20.5l18-8.5L3 3.5v6.6l12.5 1.9L3 13.9z",
  ticks: "M11.1.7L5.2 6.6 3.2 4.6 2 5.8l3.2 3.2 7.1-7.1zM14.5.7L8.6 6.6l-.6-.6-1.2 1.2L8.6 9l7.1-7.1z",
};

function Svg({ d, size = 20, box = "0 0 24 24", className }) {
  return <svg className={className} viewBox={box} width={size} height={size} aria-hidden="true"><path fill="currentColor" d={d} /></svg>;
}

const WHISH = "💳 Whish";

// The Whish logo in place of the card emoji, the way the shop's WhatsApp button shows it.
function Whish() {
  // eslint-disable-next-line @next/next/no-img-element
  return <><img src="/whish.png" alt="" width={18} height={18} className="wa-whish" />Whish</>;
}

function Text({ text }) {
  // As in WhatsApp: links look like links and *stars* make bold text.
  return text.split(/(https?:\/\/\S+|💳 Whish|\*[^*\n]+\*)/).map((part, i) => {
    if (part === WHISH) return <Whish key={i} />;
    if (i % 2 && part.startsWith("*")) return <strong key={i}>{part.slice(1, -1)}</strong>;
    return i % 2 ? <span key={i} className="wa-link">{part}</span> : part;
  });
}

/**
 * What the customer does next, so the visitor can do it: tap a button of the bot's last message
 * (a list button stands for the item picked in it), send a voice note, or send the typed text.
 */
function customerAction(messages, count) {
  const msg = messages[count];
  if (msg.voice) return { kind: "voice" };
  let i = count - 1;
  while (i >= 0 && messages[i].chat !== "c") i--;
  const bot = messages[i];
  if (bot && !bot.out && bot.buttons && !msg.photo) {
    if (bot.buttons.includes(msg.text)) return { kind: "button", at: i, label: msg.text };
    if (bot.buttons.length === 1) return { kind: "button", at: i, label: bot.buttons[0] };
  }
  return { kind: "send", draft: msg.photo ? `${msg.photo} ${msg.text}` : msg.text };
}

/** Real NeoTalab conversations for several kinds of shops, on the phones of the people involved. */
export default function OrderDemo({ demo }) {
  const [scenarioIndex, setScenarioIndex] = useState(0);
  const [count, setCount] = useState(0);
  const [typing, setTyping] = useState(null);
  const [playing, setPlaying] = useState(false);
  const [started, setStarted] = useState(false);
  // On a phone screen one chat shows at a time; a tab the visitor picks holds until the next message.
  const [view, setView] = useState(null);
  const rootRef = useRef(null);
  const stripRef = useRef(null);
  const tabsRef = useRef(null);
  const bodyRefs = useRef({});

  const shop = demo.scenarios[scenarioIndex];
  const [mode, setMode] = useState(shop.defaultMode);
  const [pay, setPay] = useState("whish");
  // Salons and clinics take bookings, so there is nothing to pick.
  const scenario = shop.booking || shop[mode][pay];
  const { messages, chats } = scenario;

  // Start when the demo scrolls into view; with reduced motion, show the whole conversation instead.
  useEffect(() => {
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
      setCount(messages.length);
      return;
    }
    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) {
        setStarted(true);
        setPlaying(true);
        observer.disconnect();
      }
    }, { threshold: 0.25 });
    if (rootRef.current) observer.observe(rootRef.current);
    return () => observer.disconnect();
  }, [messages.length]);

  useEffect(() => {
    if (!started || !playing) return;
    let timer;
    // At the end it stops; Play or Replay starts it again.
    if (count >= messages.length) {
      setPlaying(false);
      return;
    }
    const msg = messages[count];
    const prev = messages[count - 1];
    const wait = readTime(prev) + (msg.chat === "c" && msg.out ? AUTO_DELAY : OUT_DELAY) + (prev && prev.chat !== msg.chat ? SWITCH_DELAY : 0) + (prev && prev.step !== msg.step ? STEP_DELAY : 0);
    if (msg.out) {
      timer = setTimeout(() => setCount(count + 1), wait);
    } else {
      timer = setTimeout(() => {
        setTyping(msg.chat);
        timer = setTimeout(() => { setTyping(null); setCount(count + 1); }, TYPING_DELAY);
      }, wait - TYPING_DELAY / 2);
    }
    return () => { clearTimeout(timer); setTyping(null); };
  }, [count, playing, started, messages]);

  const last = count > 0 ? messages[count - 1] : null;
  // The step of the next message to show, so a jump lands on the step it picked.
  const step = count < messages.length ? messages[count].step : last?.step ?? 0;
  const next = messages[count];
  const action = started && next?.chat === "c" && next.out ? customerAction(messages, count) : null;
  const activeChat = typing || (action ? "c" : last?.chat) || chats[0];
  const shownChat = view && view.at === count && chats.includes(view.chat) ? view.chat : activeChat;
  const finished = messages.length > 0 && count >= messages.length;

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
  }, [count, typing, activeChat, shownChat, scenarioIndex]);

  function pickScenario(i) {
    const tabs = tabsRef.current;
    const tab = tabs?.querySelector(`[data-tab="${i}"]`);
    if (tabs && tab) tabs.scrollTo({ left: tab.offsetLeft - (tabs.clientWidth - tab.clientWidth) / 2, behavior: "smooth" });
    setScenarioIndex(i);
    setMode(demo.scenarios[i].defaultMode);
    setCount(0);
    setStarted(true);
    setPlaying(true);
  }

  function pickMode(next) {
    setMode(next);
    setCount(0);
    setStarted(true);
    setPlaying(true);
  }

  function pickPay(next) {
    setPay(next);
    setCount(0);
    setStarted(true);
    setPlaying(true);
  }

  // The arrows beside the shop list pick the previous or next kind of shop.
  function slideTabs(direction) {
    pickScenario((scenarioIndex + direction + demo.scenarios.length) % demo.scenarios.length);
  }

  function send() {
    setStarted(true);
    setPlaying(true);
    setCount(count + 1);
  }

  function togglePlay() {
    setStarted(true);
    if (!playing && count >= messages.length) setCount(0);
    setPlaying(!playing);
  }

  function jumpToStep(step) {
    setCount(messages.findIndex((m) => m.step === step));
    setStarted(true);
    setPlaying(true);
  }

  return (
    <div className="wa-demo" ref={rootRef}>
      <div className="wa-tabs-wrap">
        <button type="button" className="wa-icon-btn wa-prev wa-tabs-arrow" onClick={() => slideTabs(-1)} aria-label={demo.prevShop}>
          <Svg d={icons.chevron} size={18} />
        </button>
        <div className="wa-tabs" role="tablist" ref={tabsRef}>
          {demo.scenarios.map((s, i) => (
            <button key={s.id} type="button" role="tab" aria-selected={i === scenarioIndex} data-tab={i}
              className={i === scenarioIndex ? "active" : ""} onClick={() => pickScenario(i)}>
              <span aria-hidden="true">{s.icon}</span>{s.label}
            </button>
          ))}
        </div>
        <button type="button" className="wa-icon-btn wa-next wa-tabs-arrow" onClick={() => slideTabs(1)} aria-label={demo.nextShop}>
          <Svg d={icons.chevron} size={18} />
        </button>
      </div>

      <div className="wa-controls">
        {shop.booking ? (
          <p className="wa-options wa-booking-note">{demo.bookingNote}</p>
        ) : (
          <div className="wa-options">
            <div className="wa-choice">
              <span id="wa-mode-label">{demo.choose.mode}</span>
              <div className="wa-modes" role="radiogroup" aria-labelledby="wa-mode-label">
                {["delivery", "pickup"].map((m) => (
                  <button key={m} type="button" role="radio" aria-checked={mode === m} className={mode === m ? "active" : ""} onClick={() => pickMode(m)}>
                    {demo.modes[m]}
                  </button>
                ))}
              </div>
            </div>
            <div className="wa-choice">
              <span id="wa-pay-label">{demo.choose.pay}</span>
              <div className="wa-modes" role="radiogroup" aria-labelledby="wa-pay-label">
                {["whish", "cash"].map((p) => (
                  <button key={p} type="button" role="radio" aria-checked={pay === p} className={pay === p ? "active" : ""} onClick={() => pickPay(p)}>
                    {demo.pays[p] === WHISH ? <Whish /> : demo.pays[p]}
                  </button>
                ))}
              </div>
            </div>
          </div>
        )}
        <div className="wa-player">
          <button type="button" className="wa-icon-btn" onClick={finished ? () => jumpToStep(0) : togglePlay}
            aria-label={finished ? demo.replay : playing ? demo.pause : demo.play}>
            <Svg d={finished ? icons.replay : playing ? icons.pause : icons.play} size={18} />
          </button>
          <div className="wa-progress">
            {scenario.steps.map((label, i) => (
              <button key={label} type="button" className={i < step || finished ? "done" : i === step ? "current" : ""} onClick={() => jumpToStep(i)} aria-label={label} title={label} />
            ))}
          </div>
        </div>
        <p className="wa-caption" aria-live="polite">{scenario.captions[step]}</p>
      </div>

      {chats.length > 1 && (
        <div className="wa-phone-tabs" role="tablist" aria-label={demo.title}>
          {chats.map((chat) => (
            <button key={chat} type="button" role="tab" aria-selected={shownChat === chat} className={shownChat === chat ? "active" : ""}
              onClick={() => setView({ chat, at: count })}>
              {demo.who[chat]}
              {activeChat === chat && shownChat !== chat && <i className="wa-phone-tab-dot" aria-hidden="true" />}
            </button>
          ))}
        </div>
      )}

      <div className={`wa-strip wa-count-${chats.length}`} ref={stripRef}>
        {chats.map((chat) => {
          const shown = messages.map((m, i) => ({ ...m, i })).filter((m) => m.chat === chat && m.i < count);
          return (
            <div key={chat} className={`wa-col${activeChat === chat ? " is-active" : ""}${shownChat === chat ? " is-shown" : ""}`} data-chat={chat}>
              <p className="wa-who">{demo.who[chat]}</p>
              <div className="wa-phone">
                <div className="wa-status" aria-hidden="true">
                  <span className="wa-clock">{last?.time || messages[0]?.time || "12:30"}</span>
                  <span className="wa-island" />
                  <span className="wa-sys">
                    <svg width="17" height="11" viewBox="0 0 17 11" fill="currentColor"><rect x="0" y="7" width="3" height="4" rx="1" /><rect x="4.5" y="5" width="3" height="6" rx="1" /><rect x="9" y="2.5" width="3" height="8.5" rx="1" /><rect x="13.5" y="0" width="3" height="11" rx="1" /></svg>
                    <svg width="15" height="11" viewBox="0 0 15 11" fill="currentColor"><path d="M7.5 2.2c2.1 0 4 .8 5.4 2.1l1.1-1.1A9.2 9.2 0 007.5.6 9.2 9.2 0 001 3.2l1.1 1.1A7.6 7.6 0 017.5 2.2zm0 3.1c1.2 0 2.4.5 3.2 1.3l1.1-1.1a6.2 6.2 0 00-8.6 0l1.1 1.1c.8-.8 2-1.3 3.2-1.3zm0 3.1c.4 0 .8.2 1.1.5L7.5 10 6.4 8.9c.3-.3.7-.5 1.1-.5z" /></svg>
                    <svg width="25" height="12" viewBox="0 0 25 12" fill="none"><rect x="0.5" y="0.5" width="21" height="11" rx="3" stroke="currentColor" opacity=".45" /><rect x="2" y="2" width="16" height="8" rx="1.6" fill="currentColor" /><path d="M23 4v4c.8-.3 1.3-1.1 1.3-2S23.8 4.3 23 4z" fill="currentColor" opacity=".45" /></svg>
                  </span>
                </div>
                <div className="wa-head">
                  <Svg d={icons.back} size={20} className="wa-back" />
                  <span className="wa-avatar">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src="/Logo.png" alt="" width={24} height={24} />
                  </span>
                  <div className="wa-title">
                    <strong>{scenario.shop}</strong>
                    <span>{typing === chat ? demo.typing : demo.online}</span>
                  </div>
                  <Svg d={icons.video} size={21} className="wa-video" />
                  <Svg d={icons.call} size={17} />
                  <Svg d={icons.more} size={18} />
                </div>
                <div className="wa-body" ref={(el) => { bodyRefs.current[chat] = el; }}>
                  <span className="wa-date">{demo.today}</span>
                  {shown.map((m, k) => {
                    const prev = shown[k - 1];
                    const first = !prev || prev.out !== m.out || !!m.day || !!m.human !== !!prev.human;
                    return (
                      <div key={`${scenario.id}-${mode}-${pay}-${m.i}`} className="wa-row">
                        {m.day && <span className="wa-date">{m.day}</span>}
                        <div className={`wa-msg ${m.out ? "out" : "in"}${first ? " tail" : ""}`}>
                          <div className={`wa-bubble${m.photo ? " wa-has-photo" : ""}`}>
                            {m.human && <span className="wa-human">{m.human}</span>}
                            {!m.out && !m.human && first && <span className="wa-bot">🤖 {demo.bot}</span>}
                            {m.photo && <span className="wa-photo" aria-hidden="true">{m.photo}</span>}
                            {m.voice ? (
                              <span className="wa-voice" aria-label={demo.voice}>
                                <Svg d={icons.play} size={26} />
                                <span className="wa-wave">{Array.from({ length: 26 }, (_, b) => <i key={b} style={{ height: `${4 + ((b * 7) % 13)}px` }} />)}</span>
                                <span className="wa-voice-time">{m.voice}</span>
                              </span>
                            ) : (
                              m.text && <span className="wa-text"><Text text={m.text} /></span>
                            )}
                            <span className="wa-meta">
                              {m.time}
                              {m.out && <Svg d={icons.ticks} size={16} box="0 0 16 11" />}
                            </span>
                          </div>
                          {m.buttons?.map((b) => (action?.kind === "button" && action.at === m.i && action.label === b ? (
                            <button key={b} type="button" className="wa-button wa-target" onClick={send}>{b === WHISH ? <Whish /> : b}</button>
                          ) : (
                            <span key={b} className="wa-button">{b === WHISH ? <Whish /> : b}</span>
                          )))}
                        </div>
                      </div>
                    );
                  })}
                  {typing === chat && <div className="wa-row"><div className="wa-msg in tail"><div className="wa-bubble wa-typing"><i /><i /><i /></div></div></div>}
                </div>
                <div className="wa-input">
                  <span className="wa-field">
                    <Svg d={icons.emoji} />
                    {chat === "c" && action?.kind === "send" ? <em className="wa-draft">{action.draft}</em> : <em>{demo.message}</em>}
                    {!(chat === "c" && action?.kind === "send") && <><Svg d={icons.clip} size={19} /><Svg d={icons.camera} size={19} /></>}
                  </span>
                  {chat === "c" && action?.kind === "send" ? (
                    <button type="button" className="wa-mic wa-target" onClick={send} aria-label={demo.sendLabel}><Svg d={icons.send} /></button>
                  ) : chat === "c" && action?.kind === "voice" ? (
                    <button type="button" className="wa-mic wa-target" onClick={send} aria-label={demo.voice}><Svg d={icons.mic} /></button>
                  ) : (
                    <span className="wa-mic"><Svg d={icons.mic} /></span>
                  )}
                </div>
                <span className="wa-home" aria-hidden="true" />
              </div>
            </div>
          );
        })}
      </div>

      {finished && (
        <div className="wa-end">
          <p>{demo.end.title}</p>
          <div className="wa-end-actions">
            <Link href="/join" className="btn-hero primary">{demo.end.cta}</Link>
            <button type="button" className="btn-hero ghost" onClick={() => jumpToStep(0)}>{demo.end.again}</button>
          </div>
        </div>
      )}

    </div>
  );
}
