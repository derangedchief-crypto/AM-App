package net.extremeiptv.account

import android.graphics.BitmapFactory
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.*
import androidx.compose.material3.Surface
import androidx.compose.runtime.*
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.OkHttpClient
import okhttp3.Request
import java.util.concurrent.TimeUnit

private val bannerHttp = OkHttpClient.Builder().followRedirects(false).followSslRedirects(false)
    .connectTimeout(10, TimeUnit.SECONDS).callTimeout(15, TimeUnit.SECONDS).build()

@Composable
internal fun DealBanner(url: String, title: String) {
    // URLs are restricted to the AM banner endpoint by the account parser. No bearer or login is sent.
    val image by produceState<ImageBitmap?>(null, url) {
        value = withContext(Dispatchers.IO) {
            runCatching {
                bannerHttp.newCall(Request.Builder().url(url).build()).execute().use { response ->
                    check(response.isSuccessful)
                    val body = checkNotNull(response.body)
                    check(body.contentLength() <= 1048576)
                    val bytes = body.byteStream().use { stream ->
                        val out = java.io.ByteArrayOutputStream()
                        val buffer = ByteArray(8192)
                        while (out.size() <= 1048576) {
                            val count = stream.read(buffer)
                            if (count < 0) break
                            out.write(buffer, 0, count)
                        }
                        out.toByteArray()
                    }
                    check(bytes.size <= 1048576)
                    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
                    BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
                    check(bounds.outWidth in 1..4000 && bounds.outHeight in 1..4000)
                    val options = BitmapFactory.Options().apply {
                        inSampleSize = if (maxOf(bounds.outWidth, bounds.outHeight) > 2000) 4 else 2
                    }
                    checkNotNull(BitmapFactory.decodeByteArray(bytes, 0, bytes.size, options)).asImageBitmap()
                }
            }.getOrNull()
        }
    }
    Surface(color = Panel, modifier = Modifier.fillMaxWidth().heightIn(min = 80.dp, max = 220.dp)) {
        if (image != null) Image(image!!, contentDescription = title, contentScale = ContentScale.Fit,
            modifier = Modifier.fillMaxWidth().aspectRatio(image!!.width.toFloat() / image!!.height))
        else Box(Modifier.padding(18.dp)) { Hint(title) }
    }
}
