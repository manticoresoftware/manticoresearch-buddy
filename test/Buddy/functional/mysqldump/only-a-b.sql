/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `a`
--

DROP TABLE IF EXISTS `a`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE a (
id bigint
);
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `a`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `a` WRITE;
/*!40000 ALTER TABLE `a` DISABLE KEYS */;
INSERT INTO `a` VALUES (1);
/*!40000 ALTER TABLE `a` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `b`
--

DROP TABLE IF EXISTS `b`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE b (
id bigint,
v1 text,
v2 integer,
v3 json engine='rowwise'
) engine='columnar';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `b`
--
-- WHERE:  id > 0 ORDER BY id ASC

LOCK TABLES `b` WRITE;
/*!40000 ALTER TABLE `b` DISABLE KEYS */;
INSERT INTO `b` VALUES (1,'641aa6975e296',0,'{\"key\":313,\"value\":\"641aa6975e29f\"}'),(2,'641aa6975e2b0',644,'{\"key\":619,\"value\":\"641aa6975e2b1\"}'),(3,'641aa6975e2b9',1706,'{\"key\":623,\"value\":\"641aa6975e2ba\"}'),(4,'641aa6975e2c3',1011,'{\"key\":325,\"value\":\"641aa6975e2c5\"}'),(5,'641aa6975e2cc',224,'{\"key\":327,\"value\":\"641aa6975e2ce\"}'),(6,'641aa6975e2d5',4170,'{\"key\":608,\"value\":\"641aa6975e2d6\"}'),(7,'641aa6975e2dd',1068,'{\"key\":694,\"value\":\"641aa6975e2de\"}'),(8,'641aa6975e2e5',1421,'{\"key\":0,\"value\":\"641aa6975e2e7\"}'),(9,'641aa6975e2ef',1232,'{\"key\":893,\"value\":\"641aa6975e2f1\"}'),(10,'641aa6975e2f8',7785,'{\"key\":218,\"value\":\"641aa6975e2f9\"}'),(11,'641aa6975e300',7340,'{\"key\":66,\"value\":\"641aa6975e302\"}'),(12,'641aa6975e309',10681,'{\"key\":739,\"value\":\"641aa6975e30b\"}'),(13,'641aa6975e312',5304,'{\"key\":282,\"value\":\"641aa6975e313\"}'),(14,'641aa6975e31a',351,'{\"key\":901,\"value\":\"641aa6975e31c\"}'),(15,'641aa6975e323',5418,'{\"key\":28,\"value\":\"641aa6975e324\"}'),(16,'641aa6975e32b',10800,'{\"key\":559,\"value\":\"641aa6975e32d\"}'),(17,'641aa6975e334',5936,'{\"key\":477,\"value\":\"641aa6975e336\"}'),(18,'641aa6975e33c',782,'{\"key\":366,\"value\":\"641aa6975e33e\"}'),(19,'641aa6975e345',36,'{\"key\":777,\"value\":\"641aa6975e347\"}'),(20,'641aa6975e34e',5548,'{\"key\":867,\"value\":\"641aa6975e350\"}');
/*!40000 ALTER TABLE `b` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
