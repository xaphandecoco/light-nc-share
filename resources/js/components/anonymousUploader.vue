<script setup>
// Light NC Share: send files without an account.
// The limits shown here are a courtesy; the server checks every one of them again.
import { ref, computed } from 'vue'
import { FilePlus, Send, Copy, X, LogIn } from 'lucide-vue-next'
import { uploadFileWithTus } from '../api'
import { getApiUrl, niceFileSize } from '../utils'

defineEmits(['sign-in'])

const MAX_BYTES = 15 * 1024 * 1024 * 1024
const MAX_FILES = 20
const MAX_DAYS = 7

const files = ref([])
const name = ref('')
const days = ref(MAX_DAYS)
const sending = ref(false)
const progress = ref(0)
const current = ref(0)
const error = ref('')
const shareUrl = ref('')
const picker = ref(null)

const totalSize = computed(() => files.value.reduce((sum, f) => sum + f.size, 0))
const tooBig = computed(() => totalSize.value > MAX_BYTES || files.value.length > MAX_FILES)

const addFiles = (event) => {
  const picked = Array.from(event.target.files || [])
  event.target.value = ''
  for (const f of picked) {
    if (!files.value.some((x) => x.name === f.name && x.size === f.size && x.lastModified === f.lastModified)) {
      files.value.push(f)
    }
  }
}

const removeFile = (index) => {
  files.value.splice(index, 1)
}

// tus errors carry the server's plain-English refusal in the response body
const messageFrom = async (e) => {
  try {
    const body = e?.originalResponse?.getBody?.()
    if (body && body.length < 300 && !body.trim().startsWith('<')) return body
  } catch (_) {}
  return 'Something went wrong while sending. Please try again.'
}

const post = async (path, body, token) => {
  const response = await fetch(`${getApiUrl()}/api/anon/${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {})
    },
    body: JSON.stringify(body)
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) throw new Error(data.message || 'Something went wrong. Please try again.')
  return data.data
}

const uploadOne = (file, token, onBytes) =>
  new Promise((resolve, reject) => {
    uploadFileWithTus(
      file,
      (p) => onBytes(p.uploadedBytes),
      (done) => resolve(done.uploadId),
      (e) => reject(e),
      {},
      token
    )
  })

const send = async () => {
  error.value = ''
  if (files.value.length === 0) {
    error.value = 'Choose at least one file.'
    return
  }
  if (tooBig.value) {
    error.value = "Shares from people who aren't signed in can be at most 15 GB and 20 files."
    return
  }

  sending.value = true
  progress.value = 0
  try {
    const { token } = await post('upload-token', {})

    const uploadIds = []
    let doneBytes = 0
    for (const [i, file] of files.value.entries()) {
      current.value = i + 1
      uploadIds.push(
        await uploadOne(file, token, (bytes) => {
          progress.value = totalSize.value ? Math.round(((doneBytes + bytes) / totalSize.value) * 100) : 100
        })
      )
      doneBytes += file.size
    }

    const { share } = await post('create-share', { uploadIds, name: name.value || null, expiry_days: Number(days.value) }, token)
    shareUrl.value = `${window.location.origin}/shares/${share.long_id}`
  } catch (e) {
    error.value = e?.originalResponse ? await messageFrom(e) : e.message
  } finally {
    sending.value = false
  }
}

const copied = ref(false)
const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(shareUrl.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  } catch (_) {}
}

const startAgain = () => {
  files.value = []
  name.value = ''
  shareUrl.value = ''
  progress.value = 0
  current.value = 0
}
</script>

<template>
  <div class="anon-uploader">
    <!-- done -->
    <div v-if="shareUrl" class="anon-card">
      <h1>Your link is ready</h1>
      <p>Send this link to anyone. Files are deleted after {{ days }} {{ Number(days) === 1 ? 'day' : 'days' }}.</p>
      <div class="anon-link">
        <input type="text" :value="shareUrl" readonly @focus="$event.target.select()" aria-label="Share link" />
        <button class="copy" @click="copyLink"><Copy /> {{ copied ? 'Copied' : 'Copy link' }}</button>
      </div>
      <p class="anon-total" role="status">Expires in {{ days }} {{ Number(days) === 1 ? 'day' : 'days' }}.</p>
      <button class="block secondary mt-3" @click="startAgain">Send more files</button>
    </div>

    <!-- form -->
    <div v-else class="anon-card">
      <h1>Send files</h1>
      <p>No account needed. Up to 20 files and 15 GB at a time. Files are deleted after at most 7 days.</p>

      <input ref="picker" type="file" multiple class="anon-hidden" @change="addFiles" />
      <button class="block text-large" :disabled="sending" @click="picker.click()"><FilePlus /> Add files</button>

      <ul v-if="files.length" class="anon-files">
        <li v-for="(file, index) in files" :key="file.name + file.size + file.lastModified">
          <span class="anon-file-name">{{ file.name }}</span>
          <span class="anon-file-size">{{ niceFileSize(file.size) }}</span>
          <button class="icon-only secondary" :disabled="sending" :aria-label="`Remove ${file.name}`" @click="removeFile(index)">
            <X />
          </button>
        </li>
      </ul>
      <div class="anon-total" :class="{ over: tooBig }">{{ files.length }} / {{ MAX_FILES }} files, {{ niceFileSize(totalSize) }} / 15 GB</div>

      <label for="anon-name">Name (optional)</label>
      <input id="anon-name" v-model="name" type="text" maxlength="255" placeholder="For example: Event photos" :disabled="sending" />

      <label for="anon-days">Keep the link for</label>
      <select id="anon-days" v-model="days" :disabled="sending">
        <option v-for="n in MAX_DAYS" :key="n" :value="n">{{ n }} {{ n === 1 ? 'day' : 'days' }}</option>
      </select>

      <div v-if="sending" class="anon-progress" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
        <div class="anon-progress-fill" :style="{ width: progress + '%' }"></div>
        <span>{{ progress }}%</span>
      </div>
      <p v-if="sending" class="anon-total" role="status">Uploading {{ current }} of {{ files.length }}</p>

      <p v-if="error" class="anon-error" role="alert">{{ error }}</p>

      <button class="block text-large mt-3" :disabled="sending || !files.length || tooBig" @click="send">
        <Send /> {{ sending ? 'Sending...' : 'Send files' }}
      </button>

      <a href="" class="anon-signin" @click.prevent="$emit('sign-in')"><LogIn /> Staff sign in</a>
    </div>
  </div>
</template>

<style scoped lang="scss">
.anon-uploader {
  width: 100%;
}

.anon-card {
  max-width: 560px;
  margin: 0 auto;
  border: 1px solid rgba(63, 63, 64, 0.12);
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
  display: flex;
  flex-direction: column;
  gap: 10px;
  width: 100%;
  padding: 24px;
  background: #fff;
  color: #3f3f40;
  border-radius: 16px;

  h1 {
    margin: 0;
    font-family: "Anton", "Montserrat", system-ui, sans-serif;
    font-size: clamp(40px, 8vw, 56px);
    font-weight: 400;
    line-height: 0.95;
    text-transform: uppercase;
  }
  h1::before {
    content: "LIGHT NC / SHARE";
    display: block;
    margin-bottom: 10px;
    font-family: "Montserrat", system-ui, sans-serif;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.2em;
    opacity: 0.8;
  }

  p {
    margin: 0;
  }

  label {
    margin-top: 6px;
    font-size: 0.85rem;
    font-weight: 600;
  }

  select {
    height: 44px;
    padding: 0 10px;
    border-radius: var(--button-border-radius);
    border: 1px solid var(--input-border-color);
    background: var(--input-background-color);
    color: var(--input-text-color);
    font: inherit;
  }
}

.anon-hidden {
  display: none;
}

.anon-files {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 180px;
  overflow-y: auto;

  li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 6px 8px;
    background: var(--uploader-item-background-color);
    color: var(--uploader-item-text-color);
    border-radius: 8px;
    margin-bottom: 4px;
  }

  button {
    width: 32px;
    height: 32px;
    margin: 0;
    padding: 0;
    flex-shrink: 0;
  }
}

.anon-file-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.anon-file-size,
.anon-total {
  font-size: 0.8rem;
  color: var(--panel-text-color-alt);
}

.anon-total.over {
  color: var(--color-danger);
  font-weight: 600;
}

.anon-progress {
  position: relative;
  height: 28px;
  background: var(--progress-bar-background-color);
  border-radius: 14px;
  overflow: hidden;

  span {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--progress-bar-text-color);
  }
}

.anon-progress-fill {
  height: 100%;
  background: var(--progress-bar-fill-color);
  transition: width 0.2s;
}

@media (prefers-reduced-motion: reduce) {
  .anon-progress-fill { transition: none; }
}

.anon-error {
  color: var(--color-danger);
  font-weight: 600;
}

.anon-link {
  display: flex;
  gap: 8px;

  input {
    flex: 1;
    min-width: 0;
  }

  button {
    margin: 0;
  }
}

.anon-signin {
  align-self: center;
  margin-top: 6px;
  font-size: 0.85rem;

  svg {
    width: 14px;
    height: 14px;
    margin-top: -2px;
  }
}
</style>
