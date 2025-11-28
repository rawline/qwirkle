import { useEffect, useMemo, useState } from 'react'
import { useParams } from 'react-router-dom'
import { getGameState, getToken, getLogin, placeTile, finishTurn } from '../api/api.js'

export default function GameBoardPage() {
  const { gameId, playerId } = useParams()
  const [state, setState] = useState(null)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(true)
  const me = Number(playerId)
  const myLogin = getLogin()

  async function load() {
    setError('')
    try {
      const data = await getGameState({ token: getToken(), playerId })
      setState(data)
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  // polling
  useEffect(() => {
    load()
    const id = setInterval(load, 4000)
    return () => clearInterval(id)
  }, [gameId, playerId])

  const normalized = useMemo(() => normalizeGameState(state, gameId), [state, gameId])

  const players = normalized.players
  const cells = normalized.cells
  const myTiles = normalized.myTiles
  const currentTurn = normalized.currentTurn
  const timeLeft = normalized.timeLeft
  const moveTime = normalized.moveTime
  const turnDeadline = normalized.turnDeadline
  const [selectedTile, setSelectedTile] = useState(null)
  const [placing, setPlacing] = useState(false)
  const [finishing, setFinishing] = useState(false)
  const [countdown, setCountdown] = useState(null)

  const isMyTurn = currentTurn && Number(currentTurn) === me

  // Live countdown using deadline
  useEffect(() => {
    if (!turnDeadline || !moveTime) { setCountdown(timeLeft); return }
    const dl = new Date(turnDeadline).getTime()
    function tick() {
      const now = Date.now()
      let left = Math.round((dl - now) / 1000)
      if (left < 0) left = 0
      setCountdown(left)
      if (left === 0) {
        // Force refresh a moment after timeout to trigger server auto advance
        setTimeout(() => load(), 300)
      }
    }
    tick()
    const id = setInterval(tick, 1000)
    return () => clearInterval(id)
  }, [turnDeadline, moveTime])

  async function onBoardClick(e) {
    if (!selectedTile) return
    if (!isMyTurn) { setError('Сейчас не ваш ход'); return }
    // determine grid coords
    const board = e.currentTarget
    const rect = board.getBoundingClientRect()
    const size = 22
    const x = Math.floor((e.clientX - rect.left + board.scrollLeft) / size) - 20
    const y = Math.floor((e.clientY - rect.top + board.scrollTop) / size) - 20
    try {
      setPlacing(true)
      await placeTile({ game_id: Number(gameId), player_id: me, tile_id: selectedTile, x, y })
      setSelectedTile(null)
      load() // quick refresh
    } catch (err) {
      setError(err.message)
    } finally {
      setPlacing(false)
    }
  }

  async function onFinishTurn() {
    if (!isMyTurn) { setError('Сейчас не ваш ход'); return }
    try {
      setFinishing(true)
      const res = await finishTurn({ game_id: Number(gameId), player_id: me })
      setSelectedTile(null)
      // Optimistically update current turn if backend returned next_player_id
      if (res && res.next_player_id) {
        setState(prev => {
          if (!prev) return prev
          // If prev is array of games
          if (Array.isArray(prev)) {
            return prev.map(g => g.game_id == gameId ? { ...g, current_turn: res.next_player_id } : g)
          }
          // Single object
          return { ...prev, current_turn: res.next_player_id }
        })
      }
      load()
    } catch (err) {
      setError(err.message)
    } finally {
      setFinishing(false)
    }
  }

  return (
    <div className="grid">
      <section className="card">
        <h2>Игра #{gameId}</h2>
        {loading && <div>Загрузка…</div>}
        {error && <div className="error">{error}</div>}
        <div className="row wrap">
          <div className="pill">Ход: {currentTurn ? `игрок #${currentTurn}` : '—'}</div>
          <div className="pill">Время: {typeof countdown === 'number' ? `${countdown}s` : (timeLeft || '—')}</div>
        </div>
        {isMyTurn && (
          <div className="row" style={{ marginTop: 8 }}>
            <button onClick={onFinishTurn} disabled={finishing}>
              {finishing ? 'Завершение…' : 'Завершить ход'}
            </button>
          </div>
        )}
      </section>

      <section className="card">
        <h3>Игроки</h3>
        <ul className="players">
          {players.map(p => (
            <li key={p.player_id} className={`${p.player_id === currentTurn ? 'turn' : ''}`.trim()}>
              <div>
                <div className="title">{p.login || `Игрок #${p.player_id}`}{p.player_id === me ? ' (вы)' : ''}</div>
                <div className="sub">Порядок: {p.turn_order ?? '—'}</div>
              </div>
              <div className="score">{p.score ?? 0} очков</div>
            </li>
          ))}
        </ul>
      </section>

      <section className="card">
        <h3>Ваши фишки</h3>
        <div className="tiles">
          {(myTiles || []).map((t, idx) => {
            const id = t.id || t.id_tile || idx
            const selected = selectedTile === (t.id || t.id_tile)
            return (
            <div key={id} className={`tile${selected ? ' selected' : ''}`} title={`${t.color || ''} ${t.shape || ''}`.trim()} onClick={() => setSelectedTile(t.id || t.id_tile)}>
              {renderTileIcon(t)}
            </div>)
          })}
        </div>
      </section>

      <section className="card">
        <h3>Поле</h3>
  <div className="board" onClick={onBoardClick}>
          {(cells || []).slice(0, 500).map((c, idx) => (
            <div key={idx} className="cell" style={cellStyle(c)} title={`(${c.cords_x},${c.cords_y}) ${c.color || ''} ${c.shape || ''}`}>
              {renderTileIcon(c)}
            </div>
          ))}
        </div>
        <div className="sub">Всего клеток: {cells.length}</div>
      </section>

      <section className="card">
        <h3>Отладка</h3>
        <pre className="pre">{JSON.stringify(state, null, 2)}</pre>
      </section>
    </div>
  )
}

function colorToCss(color) {
  if (!color) return '#ccc'
  const map = { red: '#e74c3c', blue: '#3498db', green: '#2ecc71', yellow: '#f1c40f', purple: '#9b59b6', orange: '#e67e22' }
  return map[String(color).toLowerCase()] || '#ccc'
}

function cellStyle(c) {
  const x = parseInt(c.cords_x ?? c.x ?? 0, 10) || 0
  const y = parseInt(c.cords_y ?? c.y ?? 0, 10) || 0
  const size = 22
  return {
    transform: `translate(${(x + 20) * size}px, ${(y + 20) * size}px)`,
  }
}

// -------- helpers to normalize various DB JSON shapes --------
function normalizeGameState(raw, gameId) {
  const gid = String(gameId)
  const empty = { players: [], cells: [], myTiles: [], currentTurn: null, timeLeft: null, moveTime: null, turnDeadline: null }
  if (!raw || typeof raw !== 'object') return empty
  // If response is an array of games (as current get_game_state does), pick matching game_id or first.
  let game = null
  if (Array.isArray(raw)) {
    game = raw.find(g => g && String(g.game_id) === gid) || raw[0]
  } else if (Array.isArray(raw.games)) {
    game = raw.games.find(g => String(g.game_id) === gid) || raw.games[0]
  } else if (raw.game && typeof raw.game === 'object') {
    game = raw.game
  } else {
    game = raw
  }
  if (!game || typeof game !== 'object') return empty

  // players
  let players = []
  if (Array.isArray(game.players)) players = game.players
  else if (Array.isArray(raw.players)) players = raw.players
  else players = findArrayOfObjects(game, ['player_id', 'login'], 2) || []

  // cells
  let cells = []
  if (Array.isArray(game.cells)) cells = game.cells
  else if (Array.isArray(raw.cells)) cells = raw.cells
  else cells = findArrayOfObjects(game, ['cords_x', 'cords_y'], 2) || []

  // my tiles
  let myTiles = []
  if (Array.isArray(game.my_tiles)) myTiles = game.my_tiles
  else if (Array.isArray(raw.my_tiles)) myTiles = raw.my_tiles
  else if (Array.isArray(game.tiles)) myTiles = game.tiles
  else myTiles = []

  // current turn
  let currentTurn = pickFirstNumber(game, [
    'current_turn', 'currentPlayer', 'current_player', 'current_player_id', 'turn_player_id', 'player_turn', 'who_move', 'id_current_player'
  ])
  if (currentTurn == null) {
    currentTurn = deepPickFirstNumber(raw, [
      'current_turn', 'currentPlayer', 'current_player', 'current_player_id', 'turn_player_id', 'player_turn', 'who_move', 'id_current_player'
    ])
  }

  // time left
  let timeLeft = pickFirstNumber(game, [
    'time_left', 'timeLeft', 'time_left_seconds', 'time_remaining', 'timeRemaining', 'seconds_left', 'remaining_time'
  ])
  if (timeLeft == null) {
    timeLeft = deepPickFirstNumber(raw, [
      'time_left', 'timeLeft', 'time_left_seconds', 'time_remaining', 'timeRemaining', 'seconds_left', 'remaining_time'
    ])
  }

  // move_time
  const moveTime = pickFirstNumber(game, ['move_time']) ?? pickFirstNumber(raw, ['move_time'])
  // deadline ISO8601 string
  const turnDeadline = (game.turn_deadline || game.turnDeadline || game.deadline || null) ?? deepPickFirstString(raw, ['turn_deadline', 'turnDeadline', 'deadline'])

  return { players, cells, myTiles, currentTurn, timeLeft, moveTime, turnDeadline }
}

function findArrayOfObjects(obj, requiredKeys = [], minMatches = 1) {
  // breadth-first search through object values
  const queue = [obj]
  while (queue.length) {
    const node = queue.shift()
    if (!node || typeof node !== 'object') continue
    for (const k of Object.keys(node)) {
      const v = node[k]
      if (Array.isArray(v) && v.length && typeof v[0] === 'object') {
        const keys = new Set(Object.keys(v[0] || {}))
        const matches = requiredKeys.filter(rk => keys.has(rk)).length
        if (matches >= minMatches) return v
      } else if (v && typeof v === 'object') {
        queue.push(v)
      }
    }
  }
  return null
}

function pickFirstNumber(obj, keys) {
  for (const k of keys) {
    if (obj && Object.prototype.hasOwnProperty.call(obj, k)) {
      const val = obj[k]
      const n = typeof val === 'string' ? parseInt(val, 10) : val
      if (typeof n === 'number' && !Number.isNaN(n)) return n
    }
  }
  return null
}

// ---- UI helpers for drawing tiles ----
function renderTileIcon(t) {
  const shape = t.shape || tileShapeFromId(t.id_tile)
  const color = colorToCss(t.color || tileColorNameFromId(t.id_tile))
  const size = 18
  const styleBase = { width: size, height: size, background: 'transparent' }
  if (shape === 'circle') {
    return <div style={{ ...styleBase, background: color, borderRadius: '50%' }} />
  }
  if (shape === 'square') {
    return <div style={{ ...styleBase, background: color }} />
  }
  if (shape === 'triangle') {
    const side = size
    return <div style={{ width: 0, height: 0, borderLeft: `${side/2}px solid transparent`, borderRight: `${side/2}px solid transparent`, borderBottom: `${side}px solid ${color}` }} />
  }
  // fallback text
  return <div style={{ ...styleBase, display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#333', fontSize: 10 }}>{shape || t.id_tile}</div>
}

const SHAPES = ['square','circle','triangle']
const COLORS = ['red','blue','green','yellow','purple','orange']
function tileShapeFromId(id) {
  if (typeof id !== 'number') id = parseInt(id, 10)
  const idx = Math.floor((id || 0) / 10)
  return SHAPES[(idx % SHAPES.length + SHAPES.length) % SHAPES.length]
}
function tileColorNameFromId(id) {
  if (typeof id !== 'number') id = parseInt(id, 10)
  const idx = (id || 0) % 10
  return COLORS[(idx % COLORS.length + COLORS.length) % COLORS.length]
}

// Deep search for first numeric value with one of given keys in nested objects/arrays
function deepPickFirstNumber(root, keys) {
  const seen = new Set()
  const stack = [root]
  while (stack.length) {
    const node = stack.pop()
    if (!node || typeof node !== 'object' || seen.has(node)) continue
    seen.add(node)
    for (const k of keys) {
      if (Object.prototype.hasOwnProperty.call(node, k)) {
        const val = node[k]
        const n = typeof val === 'string' ? parseInt(val, 10) : val
        if (typeof n === 'number' && !Number.isNaN(n)) return n
      }
    }
    if (Array.isArray(node)) {
      for (const item of node) stack.push(item)
    } else {
      for (const v of Object.values(node)) stack.push(v)
    }
  }
  return null
}

function deepPickFirstString(root, keys) {
  const seen = new Set()
  const stack = [root]
  while (stack.length) {
    const node = stack.pop()
    if (!node || typeof node !== 'object' || seen.has(node)) continue
    seen.add(node)
    for (const k of keys) {
      if (Object.prototype.hasOwnProperty.call(node, k)) {
        const val = node[k]
        if (typeof val === 'string' && val.trim()) return val
      }
    }
    if (Array.isArray(node)) {
      for (const item of node) stack.push(item)
    } else {
      for (const v of Object.values(node)) stack.push(v)
    }
  }
  return null
}
