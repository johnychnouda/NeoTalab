"use client";

/**
 * Loads the Google Maps JavaScript API once for the whole app (browser key NEXT_PUBLIC_GOOGLE_MAPS_KEY,
 * restricted to neotalab.com). Places is used for the search box; everything else is core Maps.
 */
export const MAPS_KEY = process.env.NEXT_PUBLIC_GOOGLE_MAPS_KEY || "";
export const BEIRUT = { lat: 33.8938, lng: 35.5018 };

export function loadGoogleMaps(lang = "en") {
  if (!MAPS_KEY) return Promise.reject(new Error("no key"));
  if (window.google?.maps?.Map) return Promise.resolve(window.google.maps);
  window.__ntGoogleMaps ??= new Promise((resolve, reject) => {
    window.__ntMapsReady = () => resolve(window.google.maps);
    const s = document.createElement("script");
    s.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(MAPS_KEY)}&v=weekly&libraries=places,geometry`
      + `&language=${encodeURIComponent(lang)}&region=LB&callback=__ntMapsReady`;
    s.async = true;
    s.onerror = () => { window.__ntGoogleMaps = null; reject(new Error("load failed")); };
    document.head.appendChild(s);
  });
  return window.__ntGoogleMaps;
}

/** Start downloading the Maps script when a page opens, so it is ready when the map appears. */
export function preloadGoogleMaps() {
  if (typeof window === "undefined" || !MAPS_KEY) return;
  loadGoogleMaps().catch(() => {});
}

/** Dark map that matches the dashboard; roads and places stay readable. */
export const DARK_STYLE = [
  { elementType: "geometry", stylers: [{ color: "#16221c" }] },
  { elementType: "labels.text.fill", stylers: [{ color: "#9fb0a6" }] },
  { elementType: "labels.text.stroke", stylers: [{ color: "#0c1511" }] },
  { featureType: "administrative", elementType: "geometry.stroke", stylers: [{ color: "#2c4237" }] },
  { featureType: "administrative.locality", elementType: "labels.text.fill", stylers: [{ color: "#d6e2db" }] },
  { featureType: "poi", stylers: [{ visibility: "off" }] },
  { featureType: "road", elementType: "geometry", stylers: [{ color: "#26372e" }] },
  { featureType: "road", elementType: "geometry.stroke", stylers: [{ color: "#1a2921" }] },
  { featureType: "road.highway", elementType: "geometry", stylers: [{ color: "#33493d" }] },
  { featureType: "transit", stylers: [{ visibility: "off" }] },
  { featureType: "water", elementType: "geometry", stylers: [{ color: "#0b1f2a" }] },
  { featureType: "water", elementType: "labels.text.fill", stylers: [{ color: "#4f6b7a" }] },
];

export const MAP_OPTIONS = {
  styles: DARK_STYLE,
  mapTypeControl: false, streetViewControl: false, fullscreenControl: true, clickableIcons: false,
  gestureHandling: "greedy",
};

/** "33.8938, 35.5018" (or a link containing it) → { lat, lng }, else null. */
export function parsePin(value) {
  const m = String(value || "").match(/(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/);
  return m ? { lat: Number(m[1]), lng: Number(m[2]) } : null;
}
export const formatPin = ({ lat, lng }) => `${lat.toFixed(6)}, ${lng.toFixed(6)}`;

/** The shop on the map: a green map pin with a shop front inside; its tip sits on the shop's spot. */
const SHOP_PIN_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="52" viewBox="0 0 40 52">`
  + `<path d="M20 51C20 51 38 31 38 19A18 18 0 0 0 2 19C2 31 20 51 20 51Z" fill="#25d366" stroke="#fff" stroke-width="2.5"/>`
  + `<circle cx="20" cy="19" r="11" fill="#fff"/>`
  + `<path d="M13 16h14l-1.5-4h-11zM14 16v8h12v-8M18 24v-4h4v4" fill="none" stroke="#128c4a" stroke-width="1.8" stroke-linejoin="round"/>`
  + `</svg>`;

export function shopMarkerIcon(maps) {
  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(SHOP_PIN_SVG)}`,
    scaledSize: new maps.Size(40, 52),
    anchor: new maps.Point(20, 51),
  };
}
