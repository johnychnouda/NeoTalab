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

/** The shop pin drawn on the map: a green circle with the NeoTalab shop mark. */
export function shopMarkerIcon(maps) {
  return {
    path: maps.SymbolPath.CIRCLE, scale: 9, fillColor: "#25d366", fillOpacity: 1, strokeColor: "#ffffff", strokeWeight: 3,
  };
}
