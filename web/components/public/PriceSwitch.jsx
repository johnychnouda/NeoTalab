"use client";

import { useState } from "react";
import Link from "next/link";
import Icon from "@/components/public/Icon";

/**
 * One price card with a Monthly / Yearly switch (prices from the owner portal). With a single
 * plan there is no switch.
 */
export default function PriceSwitch({ plans, initial, save, included, cta, label }) {
  const [key, setKey] = useState(initial);
  const plan = plans.find((p) => p.key === key) || plans[0];

  return (
    <div className="pricing-one">
      {plans.length > 1 && (
        <div className="pricing-switch" role="radiogroup" aria-label={label}>
          {plans.map((p) => (
            <button key={p.key} type="button" role="radio" aria-checked={p.key === plan.key} className={p.key === plan.key ? "active" : ""} onClick={() => setKey(p.key)}>
              {p.label}
              {p.key === "yearly" && save ? <span className="pricing-save">{save}</span> : null}
            </button>
          ))}
        </div>
      )}
      <div className="pricing-card">
        <p className="pricing-amount"><strong dir="ltr">{plan.price}</strong><span>{plan.per}</span></p>
        <ul className="ticks">
          {included.map((item) => <li key={item}><Icon name="check" size={16} />{item}</li>)}
        </ul>
        <Link href="/join" className="btn-hero primary">{cta}<Icon name="arrow" size={18} /></Link>
      </div>
    </div>
  );
}
