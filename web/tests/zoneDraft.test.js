import { describe, expect, it } from "vitest";
import { zoneNameFor, zoneProblem } from "@/lib/zoneDraft";

const circle = { name: "", fee: "1.5", area: { type: "circle", center: [34.1, 35.6], radius: 2000 } };
const shape = { name: "", fee: "1.5", area: { type: "polygon", points: [] } };

describe("delivery area form", () => {
  it("names an unnamed circle after its size, like the hint in the field", () => {
    expect(zoneNameFor(circle, "Up to 2 km")).toBe("Up to 2 km");
    expect(zoneNameFor({ ...circle, name: "  Hamra  " }, "Up to 2 km")).toBe("Hamra");
  });

  it("still asks for a name when there is no size to name it after", () => {
    expect(zoneNameFor(shape, "")).toBe("");
    expect(zoneProblem(shape, "")).toBe("name");
    expect(zoneProblem({ ...shape, name: "Jounieh" }, "")).toBeNull();
  });

  it("asks for a fee: an empty field is not free delivery, 0 is", () => {
    expect(zoneProblem({ ...circle, fee: "" }, "Up to 2 km")).toBe("fee");
    expect(zoneProblem({ ...circle, fee: "  " }, "Up to 2 km")).toBe("fee");
    expect(zoneProblem({ ...circle, fee: "0" }, "Up to 2 km")).toBeNull();
    expect(zoneProblem({ ...circle, fee: "1.5" }, "Up to 2 km")).toBeNull();
  });
});
