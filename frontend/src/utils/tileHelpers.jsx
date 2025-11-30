export function colorToCss(color) {
  if (!color) return "#ccc";
  const map = {
    red: "#e74c3c",
    blue: "#3498db",
    green: "#2ecc71",
    yellow: "#f1c40f",
    purple: "#9b59b6",
    orange: "#e67e22",
  };
  return map[String(color).toLowerCase()] || "#ccc";
}

export function cellStyle(c) {
  const x = parseInt(c.cords_x ?? c.x ?? 0, 10) || 0;
  const y = parseInt(c.cords_y ?? c.y ?? 0, 10) || 0;
  const size = 22;
  return {
    transform: `translate(${(x + 20) * size}px, ${(y + 20) * size}px)`,
  };
}

const SHAPES = ["square", "circle", "triangle"];
const COLORS = ["red", "blue", "green", "yellow", "purple", "orange"];

export function renderTileIcon(t) {
  const shape = t.shape || tileShapeFromId(t.id_tile);
  const color = colorToCss(t.color || tileColorNameFromId(t.id_tile));
  const size = 18;
  const styleBase = { width: size, height: size, background: "transparent" };

  if (shape === "circle") {
    return (
      <div style={{ ...styleBase, background: color, borderRadius: "50%" }} />
    );
  }
  if (shape === "square") {
    return <div style={{ ...styleBase, background: color }} />;
  }
  if (shape === "triangle") {
    const side = size;
    return (
      <div
        style={{
          width: 0,
          height: 0,
          borderLeft: `${side / 2}px solid transparent`,
          borderRight: `${side / 2}px solid transparent`,
          borderBottom: `${side}px solid ${color}`,
        }}
      />
    );
  }
  // fallback text
  return (
    <div
      style={{
        ...styleBase,
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        color: "#333",
        fontSize: 10,
      }}
    >
      {shape || t.id_tile}
    </div>
  );
}

export function tileShapeFromId(id) {
  let numId = id;
  if (typeof numId !== "number") numId = parseInt(numId, 10);
  const idx = Math.floor((numId || 0) / 10);
  return SHAPES[((idx % SHAPES.length) + SHAPES.length) % SHAPES.length];
}

export function tileColorNameFromId(id) {
  let numId = id;
  if (typeof numId !== "number") numId = parseInt(numId, 10);
  const idx = (numId || 0) % 10;
  return COLORS[((idx % COLORS.length) + COLORS.length) % COLORS.length];
}
