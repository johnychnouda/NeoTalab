"use client";

import { useEffect, useRef, useState } from "react";

const OUT_DELAY = 2400;
const TYPING_DELAY = 1600;
const RESTART_DELAY = 9000;
// Extra pause when the next message is on another phone, so the eye can follow.
const SWITCH_DELAY = 1500;

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
  ticks: "M11.1.7L5.2 6.6 3.2 4.6 2 5.8l3.2 3.2 7.1-7.1zM14.5.7L8.6 6.6l-.6-.6-1.2 1.2L8.6 9l7.1-7.1z",
};

function Svg({ d, size = 20, box = "0 0 24 24", className }) {
  return <svg className={className} viewBox={box} width={size} height={size} aria-hidden="true"><path fill="currentColor" d={d} /></svg>;
}

function Text({ text }) {
  // Links (the Whish payment link) look like links, as they do in WhatsApp.
  return text.split(/(https?:\/\/\S+)/).map((part, i) => (i % 2 ? <span key={i} className="wa-link">{part}</span> : part));
}

/** Real NeoTalab conversations for several kinds of shops, on the phones of the people involved. */
export default function OrderDemo({ demo }) {
  const [scenarioIndex, setScenarioIndex] = useState(0);
  const [count, setCount] = useState(0);
  const [typing, setTyping] = useState(null);
  const [playing, setPlaying] = useState(false);
  const [started, setStarted] = useState(false);
  const rootRef = useRef(null);
  const stripRef = useRef(null);
  const bodyRefs = useRef({});

  const scenario = demo.scenarios[scenarioIndex];
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
    if (count >= messages.length) {
      timer = setTimeout(() => setCount(0), RESTART_DELAY);
      return () => clearTimeout(timer);
    }
    const msg = messages[count];
    const wait = OUT_DELAY + (count > 0 && messages[count - 1].chat !== msg.chat ? SWITCH_DELAY : 0);
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
  const step = typing ? messages[count]?.step ?? 0 : last?.step ?? 0;
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
  }, [count, typing, activeChat, scenarioIndex]);

  function pickScenario(i) {
    setScenarioIndex(i);
    setCount(0);
    setStarted(true);
    setPlaying(true);
  }

  function jumpToStep(step) {
    setCount(messages.findIndex((m) => m.step === step));
    setStarted(true);
    setPlaying(true);
  }

  return (
    <div className="wa-demo" ref={rootRef}>
      <div className="wa-tabs" role="tablist">
        {demo.scenarios.map((s, i) => (
          <button key={s.id} type="button" role="tab" aria-selected={i === scenarioIndex}
            className={i === scenarioIndex ? "active" : ""} onClick={() => pickScenario(i)}>
            <span aria-hidden="true">{s.icon}</span>{s.label}
          </button>
        ))}
      </div>
      <div className="wa-bar">
        <button type="button" className="wa-icon-btn" onClick={() => { setStarted(true); setPlaying(!playing); }} aria-label={playing ? demo.pause : demo.play}>
          <Svg d={playing ? icons.pause : icons.play} size={18} />
        </button>
        <button type="button" className="wa-icon-btn wa-prev" onClick={() => jumpToStep(Math.max(step - 1, 0))} disabled={step === 0} aria-label={demo.prev}>
          <Svg d={icons.chevron} size={18} />
        </button>
        <p className="wa-step" aria-live="polite"><span>{step + 1} / {scenario.steps.length}</span>{scenario.steps[step]}</p>
        <button type="button" className="wa-icon-btn wa-next" onClick={() => jumpToStep(Math.min(step + 1, scenario.steps.length - 1))} disabled={step === scenario.steps.length - 1} aria-label={demo.next}>
          <Svg d={icons.chevron} size={18} />
        </button>
        <button type="button" className="wa-icon-btn" onClick={() => jumpToStep(0)} aria-label={demo.replay}>
          <Svg d={icons.replay} size={18} />
        </button>
      </div>
      <div className="wa-progress">
        {scenario.steps.map((label, i) => (
          <button key={label} type="button" className={i < step ? "done" : i === step ? "current" : ""} onClick={() => jumpToStep(i)} aria-label={label} title={label} />
        ))}
      </div>

      <div className={`wa-strip wa-count-${chats.length}`} ref={stripRef} aria-hidden="true">
        {chats.map((chat) => {
          const shown = messages.map((m, i) => ({ ...m, i })).filter((m) => m.chat === chat && m.i < count);
          return (
            <div key={chat} className={`wa-col${activeChat === chat ? " is-active" : ""}`} data-chat={chat}>
              <p className="wa-who">{demo.who[chat]}</p>
              <div className="wa-phone">
                <div className="wa-head">
                  <Svg d={icons.back} size={22} className="wa-back" />
                  <span className="wa-avatar">
                    {/* eslint-disable-next-line @next/next/no-img-element */}
                    <img src="/Logo.png" alt="" width={24} height={24} />
                  </span>
                  <div className="wa-title">
                    <strong>{scenario.shop}</strong>
                    <span>{typing === chat ? demo.typing : demo.online}</span>
                  </div>
                  <Svg d={icons.video} size={21} />
                  <Svg d={icons.call} size={19} />
                  <Svg d={icons.more} size={20} />
                </div>
                <div className="wa-body" ref={(el) => { bodyRefs.current[chat] = el; }}>
                  <span className="wa-date">{demo.today}</span>
                  {shown.map((m, k) => {
                    const prev = shown[k - 1];
                    const first = !prev || prev.out !== m.out || !!m.day || !!m.human !== !!prev.human;
                    return (
                      <div key={`${scenario.id}-${m.i}`} className="wa-row">
                        {m.day && <span className="wa-date">{m.day}</span>}
                        <div className={`wa-msg ${m.out ? "out" : "in"}${first ? " tail" : ""}`}>
                          <div className={`wa-bubble${m.photo ? " wa-has-photo" : ""}`}>
                            {m.human && <span className="wa-human">{m.human}</span>}
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
                          {m.buttons?.map((b) => <span key={b} className="wa-button">{b}</span>)}
                        </div>
                      </div>
                    );
                  })}
                  {typing === chat && <div className="wa-row"><div className="wa-msg in tail"><div className="wa-bubble wa-typing"><i /><i /><i /></div></div></div>}
                </div>
                <div className="wa-input">
                  <span className="wa-field">
                    <Svg d={icons.emoji} />
                    <em>{demo.message}</em>
                    <Svg d={icons.clip} size={19} />
                    <Svg d={icons.camera} size={19} />
                  </span>
                  <span className="wa-mic"><Svg d={icons.mic} /></span>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
