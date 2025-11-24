import { useState } from 'react'
import { useNavigate, Link } from 'react-router-dom'
import { register as apiRegister, setToken, setLogin } from '../api/api.js'

export default function RegisterPage() {
  const [login, setLoginState] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  async function onSubmit(e) {
    e.preventDefault()
    setError('')
    if (login.length > 30) {
      setError('Логин слишком длинный (макс. 30)')
      return
    }
    if (password.length < 3) {
      setError('Пароль слишком короткий (мин. 3 символа)')
      return
    }
    if (password !== confirm) {
      setError('Пароли не совпадают')
      return
    }
    setLoading(true)
    try {
      const res = await apiRegister(login, password)
      if (res.error) {
        setError(res.error)
      } else if (res.token) {
        setToken(res.token)
        setLogin(login)
        navigate('/games')
      } else {
        setError('Неизвестный ответ сервера')
      }
    } catch (err) {
      setError(err.message)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="card narrow">
      <h1>Регистрация</h1>
      <form onSubmit={onSubmit} className="form">
        <label>
          Логин
          <input value={login} onChange={(e) => setLoginState(e.target.value)} placeholder="login" required maxLength={30} />
        </label>
        <label>
          Пароль
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder="password" required />
        </label>
        <label>
          Повторите пароль
          <input type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="repeat password" required />
        </label>
        {error && <div className="error">{error}</div>}
        <button className="btn" disabled={loading}>
          {loading ? 'Создаём…' : 'Зарегистрироваться'}
        </button>
        <div className="sub">Уже есть аккаунт? <Link to="/login">Войти</Link></div>
      </form>
    </div>
  )
}
